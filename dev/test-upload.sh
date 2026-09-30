#!/bin/sh
# Skin upload, end to end, against the dev instance: the real routes, real
# sessions, real files.
#
#   docker exec theme-selector-dev-librenms-1 sh /plugin/dev/test-upload.sh
#
# What it proves, beyond the unit tests (php tests/run.php), is the wiring:
# that the admin gate and CSRF check are in front of the endpoints, that a
# rejected bundle leaves nothing behind, that what lands in the web root is
# exactly and only the generated stylesheet, and that removal can't be steered
# outside the skins directory. It takes about three minutes because the upload
# route is rate limited (12 a minute) and the test waits out the window.
set -u

B=http://127.0.0.1:8000
PUB=/opt/librenms/html/css/custom/theme-selector
FIX=/tmp/ts-fixtures
DB="mariadb -h db -u librenms -plibrenms-dev librenms -N -e"
FAILED=0
PAGE=""; CODE=""

q() { $DB "$1"; }
cfg() { gosu librenms php /opt/librenms/lnms config:get "$1" 2>/dev/null | tr -d '\n '; }
check() { if [ "$2" = ok ]; then echo "  ok    $1"; else echo "  FAIL  $1"; FAILED=1; fi; }
yes_if() { if eval "$1"; then echo ok; else echo no; fi; }
jar() { echo "/tmp/tsj-$1"; }

login() { rm -f "$(jar "$1")"; curl -s -o /dev/null -c "$(jar "$1")" -b "$(jar "$1")" -H "X-Dev-User: $1" $B/devices; }
get() { curl -s -b "$(jar "$1")" -c "$(jar "$1")" -H "X-Dev-User: $1" "$B$2"; }
token() { get "$1" /plugin/theme-selector | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
text() { sed -e 's/<script[^>]*>[^<]*<\/script>//g' -e 's/<[^>]*>/ /g' | tr -s ' \n' ' '; }

# upload <user> <file> [filename] [content-type]: sets CODE (of the POST) and PAGE (the page after it)
upload() {
  tok="$(token "$1")"
  CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$(jar "$1")" -c "$(jar "$1")" -H "X-Dev-User: $1" \
    -F "_token=$tok" -F "bundle=@$2;filename=${3:-bundle.zip};type=${4:-application/zip}" $B/plugin/theme-selector/skins)"
  PAGE="$(get "$1" /plugin/theme-selector | text)"
}
post() { # user path data -> HTTP code, page in PAGE
  tok="$(token "$1")"
  CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$(jar "$1")" -c "$(jar "$1")" -H "X-Dev-User: $1" --data "_token=$tok&$3" "$B$2")"
  PAGE="$(get "$1" /plugin/theme-selector | text)"
}
skin_of() { get "$1" /devices | grep -o '<meta name="theme-selector" content="[^"]*"' | cut -d'"' -f4; }
skin_dirs() { ls -1 "$PUB/skins" 2>/dev/null | grep -v '^\.' | tr '\n' ' '; }
leftovers() { ls -1A "$PUB/skins" 2>/dev/null | grep -c '^\.\(stage\|old\)-'; }

reset_state() {
  q "delete from theme_selector_skins" >/dev/null
  q "delete from theme_selector_settings where name='default_skin'" >/dev/null
  q "delete from users_prefs where pref='theme_selector.skin'" >/dev/null
  for d in "$PUB"/skins/*; do
    case "$(basename "$d")" in terran|protoss|zerg) ;; *) rm -rf "$d" ;; esac
  done
  rm -rf "$PUB"/skins/.stage-* "$PUB"/skins/.old-*
}

echo "== setup"
rm -rf "$FIX"; php /plugin/dev/make-fixtures.php "$FIX" || exit 1
reset_state
login dev-admin; login dev-user
ADMIN_ID="$(q "select user_id from users where username='dev-admin'")"
STOCK_GREENS="$(cfg graph_colours.greens)"; STOCK_FONT="$(cfg rrdgraph_def_text_color_dark)"
TERRAN_SUM="$(sha256sum "$PUB/skins/terran/skin.css" | cut -c1-16)"
BASE_SKINS="$(skin_dirs)"

echo "== every fixture through the CLI validator (no rate limit)"
for f in "$FIX"/good-*.zip; do
  out="$(cd /opt/librenms && gosu librenms php artisan theme-selector:validate "$f" 2>&1)"
  check "validate accepts $(basename "$f")" "$(yes_if "printf '%s' \"\$out\" | grep -q '^Accepted'")"
done
for f in "$FIX"/evil-*.zip "$FIX"/notzip.zip "$FIX"/php-as-zip.zip "$FIX"/oversize.zip; do
  out="$(cd /opt/librenms && gosu librenms php artisan theme-selector:validate "$f" 2>&1)"
  check "validate refuses $(basename "$f")" "$(yes_if "printf '%s' \"\$out\" | grep -q 'Not accepted'")"
done
check "the shape of the CLI verdicts is a clean exit code" "$(yes_if "! (cd /opt/librenms && gosu librenms php artisan theme-selector:validate $FIX/evil-bomb.zip >/dev/null 2>&1)")"

echo "== who may upload"
upload dev-user "$FIX/good-slate.zip"
check "a non-admin's upload is forbidden (403)" "$(yes_if "[ '$CODE' = 403 ]")"
check "and installed nothing" "$(yes_if "[ \"\$(skin_dirs)\" = \"$BASE_SKINS\" ] && [ \"\$(q 'select count(*) from theme_selector_skins')\" = 0 ]")"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$(jar dev-admin)" -H 'X-Dev-User: dev-admin' -F 'bundle=@'"$FIX"'/good-slate.zip' $B/plugin/theme-selector/skins)"
check "an admin upload without a CSRF token is refused (419)" "$(yes_if "[ '$CODE' = 419 ]")"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$(jar dev-admin)" -H 'X-Dev-User: dev-admin' -F '_token=forged' -F 'bundle=@'"$FIX"'/good-slate.zip' $B/plugin/theme-selector/skins)"
check "an admin upload with a forged CSRF token is refused (419)" "$(yes_if "[ '$CODE' = 419 ]")"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$(jar dev-admin)" -H 'X-Dev-User: dev-admin' $B/plugin/theme-selector/skins)"
check "a GET to the upload URL is not an upload (LibreNMS answers 404)" "$(yes_if "[ '$CODE' = 404 ] || [ '$CODE' = 405 ]")"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -H 'X-Dev-User: dev-admin' -F 'bundle=@'"$FIX"'/good-slate.zip' $B/plugin/theme-selector/skins)"
check "an upload with no session at all is not accepted" "$(yes_if "[ '$CODE' != 302 ] || [ \"\$(q 'select count(*) from theme_selector_skins')\" = 0 ]")"
check "still nothing installed" "$(yes_if "[ \"\$(q 'select count(*) from theme_selector_skins')\" = 0 ]")"

echo "== hostile bundles, over HTTP: refused, explained, nothing left behind"
for spec in "evil-traversal:not allowed" "evil-symlink:not a regular file" "evil-bomb:expands beyond" "evil-css-url:not allowed" \
            "evil-css-structural:structural" "evil-font-php:PHP or script" "evil-manifest-xss:plain text" "notzip:not a zip" "oversize:larger than"; do
  f="${spec%%:*}"; why="${spec#*:}"
  upload dev-admin "$FIX/$f.zip"
  check "$f is refused and says why" "$(yes_if "printf '%s' \"\$PAGE\" | grep -qi '$why' && printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
done
check "none of them installed anything" "$(yes_if "[ \"\$(skin_dirs)\" = \"$BASE_SKINS\" ] && [ \"\$(q 'select count(*) from theme_selector_skins')\" = 0 ]")"
check "and left no staging directories" "$(yes_if "[ \"\$(leftovers)\" = 0 ]")"
check "the hostile text is never echoed back as markup" "$(yes_if "! get dev-admin /plugin/theme-selector | grep -q '<script>alert'")"

echo "== waiting out the upload rate limit"
sleep 62

echo "== a valid bundle"
upload dev-admin "$FIX/good-slate.zip" "evil.php" "image/png"
check "it installs (whatever the client called the file)" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Installed Slate Teal'")"
check "and shows in the list as an uploaded skin" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Slate Teal' && printf '%s' \"\$PAGE\" | grep -q 'Uploaded'")"
check "its directory holds exactly one file: skin.css" "$(yes_if "[ \"\$(ls -A $PUB/skins/slate-teal)\" = skin.css ]")"
check "files are 644 and the directory 755" "$(yes_if "[ \"\$(stat -c %a $PUB/skins/slate-teal/skin.css)\" = 644 ] && [ \"\$(stat -c %a $PUB/skins/slate-teal)\" = 755 ]")"
check "the registry row records who installed it" "$(yes_if "[ \"\$(q \"select installed_by from theme_selector_skins where id='slate-teal'\")\" = '$ADMIN_ID' ]")"
CSS="$(curl -s -D /tmp/tsh -H 'X-Dev-User: dev-admin' $B/css/custom/theme-selector/skins/slate-teal/skin.css)"
check "nginx serves it as text/css" "$(yes_if "grep -qi '^content-type: text/css' /tmp/tsh")"
check "it is the generated stylesheet (has the header, no url())" "$(yes_if "printf '%s' \"\$CSS\" | head -1 | grep -q 'Theme Selector skin: generated' && ! printf '%s' \"\$CSS\" | grep -qi 'url('")"
CODE="$(curl -s -o /dev/null -w '%{http_code}' $B/css/custom/theme-selector/skins/slate-teal/skin.css/x.php)"
check "the path-info trick (skin.css/x.php) does not execute it" "$(yes_if "[ '$CODE' = 404 ]")"
post dev-user /plugin/theme-selector "skin=slate-teal"
check "a user can choose it" "$(yes_if "[ \"\$(skin_of dev-user)\" = slate-teal ]")"
check "and the page links exactly base.css and its skin.css" "$(yes_if "[ \"\$(get dev-user /devices | grep -c 'data-theme-selector')\" = 2 ]")"
check "another user is unaffected" "$(yes_if "[ \"\$(skin_of dev-admin)\" = none ]")"

echo "== replacing a skin"
BEFORE_CREATED="$(q "select created_at from theme_selector_skins where id='slate-teal'")"
sleep 1
upload dev-admin "$FIX/good-slate-v2.zip"
check "re-uploading the same id replaces it" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Replaced Slate Teal'")"
check "the new stylesheet is what is served" "$(yes_if "curl -s $B/css/custom/theme-selector/skins/slate-teal/skin.css | grep -q '#ffb000'")"
check "the original creation time is kept" "$(yes_if "[ \"\$(q \"select created_at from theme_selector_skins where id='slate-teal'\")\" = '$BEFORE_CREATED' ]")"
check "no .old-/.stage- directories remain" "$(yes_if "[ \"\$(leftovers)\" = 0 ]")"
check "still one row for it" "$(yes_if "[ \"\$(q \"select count(*) from theme_selector_skins where id='slate-teal'\")\" = 1 ]")"

echo "== ids that must not be taken"
upload dev-admin "$FIX/collide-bundled.zip"
check "a bundled skin's id can't be taken" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'belongs to a bundled skin'")"
check "and the bundled skin is untouched" "$(yes_if "[ \"\$(sha256sum $PUB/skins/terran/skin.css | cut -c1-16)\" = '$TERRAN_SUM' ]")"
upload dev-admin "$FIX/reserved-id.zip"
check "a reserved id is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -qi 'reserved'")"

echo "== fonts are embedded, never served as files"
upload dev-admin "$FIX/good-font.zip"
check "a bundle with a font installs" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Installed With Font'")"
check "its directory still holds only skin.css" "$(yes_if "[ \"\$(ls -A $PUB/skins/with-font)\" = skin.css ]")"
check "the font is inside the stylesheet as a data: URL" "$(yes_if "grep -q 'url(\"data:font/woff2;base64,' $PUB/skins/with-font/skin.css")"
check "no font file exists anywhere in its directory" "$(yes_if "[ \"\$(find $PUB/skins/with-font -type f | wc -l)\" = 1 ]")"

echo "== licence notices: stored and shown, never served"
upload dev-admin "$FIX/good-license.zip"
check "a bundle with a LICENSE.txt installs" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Installed With License'")"
check "the notice is offered on the admin page" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Licence notice'")"
RAW="$(get dev-admin /plugin/theme-selector)"
check "its text is shown" "$(yes_if "printf '%s' \"\$RAW\" | grep -q 'SIL Open Font License, Version 1.1'")"
check "markup in it is shown escaped, never as markup" "$(yes_if "printf '%s' \"\$RAW\" | grep -q 'licence-xss' && ! printf '%s' \"\$RAW\" | grep -q \"<script>alert('licence-xss')\"")"
check "it is stored in the database" "$(yes_if "[ \"\$(q \"select count(*) from theme_selector_skins where id='with-license' and license_text like '%SIL Open Font License%'\")\" = 1 ]")"
check "and is NOT a file in the web root (the skin directory still holds only skin.css)" "$(yes_if "[ \"\$(find $PUB/skins/with-license -type f | wc -l)\" = 1 ] && [ -f $PUB/skins/with-license/skin.css ]")"
check "nor is it in the generated stylesheet" "$(yes_if "! grep -qi 'licence-xss\|Open Font License' $PUB/skins/with-license/skin.css")"
upload dev-admin "$FIX/evil-license-control.zip"
check "a notice with control and spoofing characters is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'LICENSE.txt' && printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
upload dev-admin "$FIX/evil-license-name.zip"
check "a wrongly named licence file is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -qi 'not allowed' && printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
check "neither installed anything" "$(yes_if "[ \"\$(q \"select count(*) from theme_selector_skins where id='evil'\")\" = 0 ] && [ ! -e $PUB/skins/evil ]")"

echo "== waiting out the upload rate limit (again)"
sleep 62

echo "== ornaments: only uploaded skins get the ornament layer"
upload dev-admin "$FIX/good-frames.zip"
check "a skin painting frame slots installs" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Installed With Frames'")"
check "its generated stylesheet carries the slot" "$(yes_if "grep -q -e '--ts-frame-tl: linear-gradient' $PUB/skins/with-frames/skin.css")"
upload dev-admin "$FIX/evil-frames-url.zip"
check "a frame slot with a url() is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
upload dev-admin "$FIX/evil-motion-fast.zip"
check "a breathing period under 2s is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'period of 2s to 60s' && printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
upload dev-admin "$FIX/evil-glow-shadow.zip"
check "an alert glow carrying a second, huge shadow is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'plain colour' && printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
upload dev-admin "$FIX/evil-cut-fill.zip"
check "a panel cut fill carrying a second shadow is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'plain colour' && printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
upload dev-admin "$FIX/evil-cut-huge.zip"
check "a 40px widget cut is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'out of range' && printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
upload dev-admin "$FIX/evil-chamfer-percent.zip"
check "a cut corner sized in % is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'in px' && printf '%s' \"\$PAGE\" | grep -q 'was not installed'")"
post dev-user /plugin/theme-selector "skin=with-frames"
check "an uploaded skin's links are marked data-ts-orn (base.css and skin.css)" "$(yes_if "[ \"\$(get dev-user /devices | grep -c 'data-ts-orn')\" = 2 ]")"
post dev-user /plugin/theme-selector "skin=terran"
check "and it keeps its motion tokens in the generated stylesheet" "$(yes_if "grep -q -e '--ts-navbar-strip-bottom-breathe: 6s' $PUB/skins/with-frames/skin.css")"
check "a bundled skin's links are not" "$(yes_if "[ \"\$(get dev-user /devices | grep -c 'data-theme-selector')\" = 2 ] && [ \"\$(get dev-user /devices | grep -c 'data-ts-orn')\" = 0 ]")"
check "and base.css carries the gated layer" "$(yes_if "grep -q 'link\[data-ts-orn\]' $PUB/base.css")"

echo "== waiting out the upload rate limit again"
sleep 62

echo "== the escape hatch"
post dev-user /plugin/theme-selector "skin=slate-teal"
check "?theme-selector=off shows a page with no skin" "$(yes_if "[ \"\$(get dev-user '/devices?theme-selector=off' | grep -c 'data-theme-selector')\" = 0 ]")"
check "and says so" "$(yes_if "get dev-user '/devices?theme-selector=off' | grep -q 'content=\"off\"'")"
check "it changes nothing stored" "$(yes_if "[ \"\$(skin_of dev-user)\" = slate-teal ]")"

echo "== the default, graphs and removal"
upload dev-admin "$FIX/good-graph.zip"
check "a bundle with a graph palette installs" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Installed Slate Graph'")"
post dev-admin /plugin/theme-selector/default "default=slate-graph"
check "it can be made the default" "$(yes_if "[ \"\$(q \"select value from theme_selector_settings where name='default_skin'\")\" = '\"slate-graph\"' ]")"
check "which applies its graph palette" "$(yes_if "[ \"\$(cfg graph_colours.greens)\" = '[\"101010\",\"202020\",\"303030\"]' ] && [ \"\$(cfg rrdgraph_def_text_color_dark)\" = abcdef ]")"
check "and a user who follows the default gets it" "$(yes_if "post dev-user /plugin/theme-selector 'skin=' ; [ \"\$(skin_of dev-user)\" = slate-graph ]")"
post dev-user /plugin/theme-selector "skin=slate-graph"
post dev-admin /plugin/theme-selector/skins/slate-graph/delete ""
check "removing the default skin works" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Removed Slate Graph'")"
check "its files and row are gone" "$(yes_if "[ ! -e $PUB/skins/slate-graph ] && [ \"\$(q \"select count(*) from theme_selector_skins where id='slate-graph'\")\" = 0 ]")"
check "the default is cleared" "$(yes_if "[ \"\$(q \"select count(*) from theme_selector_settings where name='default_skin'\")\" = 0 ]")"
check "and the graph colours are back to stock" "$(yes_if "[ \"\$(cfg graph_colours.greens)\" = '$STOCK_GREENS' ] && [ \"\$(cfg rrdgraph_def_text_color_dark)\" = '$STOCK_FONT' ]")"
check "a user who had chosen it falls back to no skin" "$(yes_if "[ \"\$(skin_of dev-user)\" = none ]")"
check "no graph override rows are left behind" "$(yes_if "[ \"\$(q \"select count(*) from config where config_name like 'graph_colours.%' or config_name like 'rrdgraph_def_text%'\")\" = 0 ]")"

echo "== removal is confined"
post dev-user /plugin/theme-selector/skins/slate-teal/delete ""
check "a non-admin can't remove a skin (403)" "$(yes_if "[ '$CODE' = 403 ]")"
check "and it is still there" "$(yes_if "[ -d $PUB/skins/slate-teal ]")"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$(jar dev-admin)" -H 'X-Dev-User: dev-admin' -X POST --data '' $B/plugin/theme-selector/skins/slate-teal/delete)"
check "a removal without a CSRF token is refused (419)" "$(yes_if "[ '$CODE' = 419 ]")"
for bad in '..%2f..%2fetc' '..%2f..%2f..%2fopt' 'a.b' 'UPPER' 'x%0a' '-x' '%2e%2e' 'fonts%2f..' 'a%00b' "$(printf 'a%.0s' $(seq 1 70))"; do
  tok="$(token dev-admin)"
  CODE="$(curl -s --path-as-is -o /dev/null -w '%{http_code}' -b "$(jar dev-admin)" -H 'X-Dev-User: dev-admin' --data "_token=$tok" "$B/plugin/theme-selector/skins/$bad/delete")"
  check "delete of id '$(printf '%.30s' "$bad")' does not reach the controller (404)" "$(yes_if "[ '$CODE' = 404 ] || [ '$CODE' = 400 ]")"
done
post dev-admin /plugin/theme-selector/skins/terran/delete ""
check "a bundled skin can't be removed" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Bundled skins can not be removed'")"
check "and is untouched" "$(yes_if "[ \"\$(sha256sum $PUB/skins/terran/skin.css | cut -c1-16)\" = '$TERRAN_SUM' ]")"
post dev-admin /plugin/theme-selector/skins/never-installed/delete ""
check "an unknown id is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'no uploaded skin'")"

echo "== a symlinked skin directory is unlinked, not followed"
upload dev-admin "$FIX/good-linky.zip"
mkdir -p /tmp/precious && echo keep > /tmp/precious/file && echo keep2 > /tmp/precious/file2
rm -rf "$PUB/skins/linky" && ln -s /tmp/precious "$PUB/skins/linky"
post dev-admin /plugin/theme-selector/skins/linky/delete ""
check "the link is gone" "$(yes_if "[ ! -e $PUB/skins/linky ] && [ ! -L $PUB/skins/linky ]")"
check "what it pointed at is untouched" "$(yes_if "[ \"\$(cat /tmp/precious/file)\" = keep ] && [ \"\$(cat /tmp/precious/file2)\" = keep2 ]")"
check "and the row is gone" "$(yes_if "[ \"\$(q \"select count(*) from theme_selector_skins where id='linky'\")\" = 0 ]")"

echo "== a directory nobody registered is not a skin"
mkdir -p "$PUB/skins/rogue" && echo 'html.dark { --ts-bg: red; }' > "$PUB/skins/rogue/skin.css" && chmod 755 "$PUB/skins/rogue"
check "it is not offered" "$(yes_if "! get dev-admin /plugin/theme-selector | text | grep -qi rogue")"
post dev-user /plugin/theme-selector "skin=rogue"
check "choosing it is refused" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Unknown skin'")"
UID2="$(q "select user_id from users where username='dev-user'")"
q "insert into users_prefs (user_id, pref, value) values ($UID2, 'theme_selector.skin', 'rogue') on duplicate key update value='rogue'" >/dev/null
check "even a stored preference for it never emits a link" "$(yes_if "[ \"\$(get dev-user /devices | grep -c 'skins/rogue')\" = 0 ] && [ \"\$(skin_of dev-user)\" != rogue ]")"
rm -rf "$PUB/skins/rogue"

echo "== the rate limit"
n429=0
for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
  tok="$(token dev-admin)"
  c="$(curl -s -o /dev/null -w '%{http_code}' -b "$(jar dev-admin)" -H 'X-Dev-User: dev-admin' -F "_token=$tok" -F "bundle=@$FIX/notzip.zip" $B/plugin/theme-selector/skins)"
  [ "$c" = 429 ] && n429=$((n429 + 1))
done
check "a burst of uploads is rate limited (429)" "$(yes_if "[ $n429 -ge 1 ]")"

echo "== what is in the web root, at the end"
reset_state
upload_ok=0
sleep 62
upload dev-admin "$FIX/good-slate.zip"; upload dev-admin "$FIX/good-font.zip"; upload dev-admin "$FIX/good-graph.zip"
UNEXPECTED="$(find "$PUB" -type f ! -name base.css ! -name .bundled.json ! -name .install.lock ! -path "$PUB/skins/terran/*" ! -path "$PUB/skins/protoss/*" ! -path "$PUB/skins/zerg/*" ! -path "$PUB/skins/slate-teal/skin.css" ! -path "$PUB/skins/with-font/skin.css" ! -path "$PUB/skins/with-license/skin.css" ! -path "$PUB/skins/with-frames/skin.css" ! -path "$PUB/skins/slate-graph/skin.css")"
check "only base.css, bundled skins and each uploaded skin's single skin.css exist" "$(yes_if "[ -z '$UNEXPECTED' ]")"
check "there are no PHP, HTML, script or config files anywhere in it" "$(yes_if "[ \"\$(find $PUB -type f \( -iname '*.php*' -o -iname '*.phtml' -o -iname '*.htm*' -o -iname '*.js' -o -iname '.htaccess' -o -iname '*.svg' -o -iname '*.sh' \) | wc -l)\" = 0 ]")"
check "no file in it is executable" "$(yes_if "[ \"\$(find $PUB -type f -perm /111 | wc -l)\" = 0 ]")"
check "no symlinks are in it" "$(yes_if "[ \"\$(find $PUB -type l | wc -l)\" = 0 ]")"
check "every uploaded skin.css passes an independent scan (ASCII only, no url( but data: fonts, no @import)" "$(yes_if "for f in $PUB/skins/slate-teal/skin.css $PUB/skins/with-font/skin.css $PUB/skins/slate-graph/skin.css; do LC_ALL=C grep -q '[^[:print:][:space:]]' \$f && exit 1; grep -qi '@import\|expression\|javascript:' \$f && exit 1; [ \"\$(grep -io 'url(' \$f | wc -l)\" = \"\$(grep -o 'url(\"data:font/woff2;base64,' \$f | wc -l)\" ] || exit 1; done; true")"
reset_state

echo
if [ $FAILED = 0 ]; then echo "all checks passed"; else echo "SOME CHECKS FAILED"; fi
exit $FAILED
