#!/bin/sh
# The picker's preview, end to end, against the dev instance: real routes, real sessions.
#
#   docker exec theme-selector-dev-librenms-1 sh /plugin/dev/test-picker.sh
#
# What it proves beyond the unit tests: that the preview page shows exactly the skin it is asked
# for and nothing else changes (not the visitor's own skin, not any other page), that an id that
# is not an installed skin is a 404, that it needs a login, that the picker page offers the right
# choices and previews the right one, and that applying a skin is a separate, explicit POST.
set -u

B=http://127.0.0.1:8000
DB="mariadb -h db -u librenms -plibrenms-dev librenms -N -e"
FAILED=0

q() { $DB "$1"; }
check() { if [ "$2" = ok ]; then echo "  ok    $1"; else echo "  FAIL  $1"; FAILED=1; fi; }
yes_if() { if eval "$1"; then echo ok; else echo no; fi; }
jar() { echo "/tmp/tsp-$1"; }
login() { rm -f "$(jar "$1")"; curl -s -o /dev/null -c "$(jar "$1")" -b "$(jar "$1")" -H "X-Dev-User: $1" $B/devices; }
get() { curl -s -b "$(jar "$1")" -c "$(jar "$1")" -H "X-Dev-User: $1" "$B$2"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$(jar "$1")" -c "$(jar "$1")" -H "X-Dev-User: $1" "$B$2"; }
token() { get "$1" /plugin/theme-selector | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
choose() { # user value
  tok="$(token "$1")"
  curl -s -o /dev/null -b "$(jar "$1")" -c "$(jar "$1")" -H "X-Dev-User: $1" --data "_token=$tok&skin=$2" $B/plugin/theme-selector
}
skin_of() { get "$1" /devices | grep -o '<meta name="theme-selector" content="[^"]*"' | cut -d'"' -f4; }
links() { grep -c 'data-theme-selector'; }

echo "== setup"
q "delete from users_prefs where pref='theme_selector.skin'" >/dev/null
q "delete from theme_selector_settings where name='default_skin'" >/dev/null
login dev-admin; login dev-user
BUNDLED="$(ls -1 /plugin/skins)"
BUNDLED_LINE="$(echo $BUNDLED)"

echo "== the preview page"
CODE="$(curl -s -o /dev/null -w '%{http_code}' $B/plugin/theme-selector/preview/zerg)"
# (The dev instance logs anonymous requests in as dev-admin, so a request can't be made without
# a user here; the route's middleware says what guards it.)
ROUTE="$(cd /opt/librenms && gosu librenms php artisan route:list --path=plugin/theme-selector/preview --json 2>/dev/null)"
check "needs a login (the route sits behind web + auth, and is not admin-only)" "$(yes_if "printf '%s' '$ROUTE' | grep -q 'Authenticate' && ! printf '%s' '$ROUTE' | grep -qi 'can:admin\|Authorize'")"
for id in $BUNDLED; do
  PAGE="$(get dev-user /plugin/theme-selector/preview/$id)"
  check "$id: shows that skin's stylesheet and base.css, and nothing else" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | links)\" = 2 ] && printf '%s' \"\$PAGE\" | grep -q 'skins/$id/skin.css'")"
  check "$id: is shown in dark mode" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q \"classList.add('dark')\"")"
  check "$id: carries the sample content (and says it is invented)" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'port_bits' && printf '%s' \"\$PAGE\" | grep -q 'invented'")"
  check "$id: has a sample graph drawn in the skin's colours" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q '<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 480 130\"'")"
  check "$id: no rabbit, whatever the skin asks for" "$(yes_if "! printf '%s' \"\$PAGE\" | grep -q 'ts-white-rabbit'")"
done
PAGE="$(get dev-user /plugin/theme-selector/preview/none)"
check "none: stock, no skin links, and says so in the meta tag" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | links)\" = 0 ] && printf '%s' \"\$PAGE\" | grep -q 'content=\"none\"'")"
check "digital-rain: asks for the ornament layer, as it does everywhere" "$(yes_if "get dev-user /plugin/theme-selector/preview/digital-rain | grep -q 'data-ts-orn'")"
for bad in nope BAD 'a%2Fb' '..%2F..%2Fetc' 'zerg%0A' '.hidden' ''; do
  check "an id that is not an installed skin is refused: '$bad'" "$(yes_if "[ \"\$(code dev-user '/plugin/theme-selector/preview/$bad')\" != 200 ]")"
done
check "a query string cannot change the skin shown ('?skin=terran' on the zerg preview)" "$(yes_if "get dev-user '/plugin/theme-selector/preview/zerg?skin=terran&preview=terran' | grep -q 'skins/zerg/skin.css' && ! get dev-user '/plugin/theme-selector/preview/zerg?skin=terran' | grep -q 'skins/terran/skin.css'")"

echo "== looking does not change anything"
check "the visitor's own skin is unchanged after previews" "$(yes_if "[ \"\$(skin_of dev-user)\" = none ]")"
check "no preference was saved" "$(yes_if "[ \"\$(q \"select count(*) from users_prefs where pref='theme_selector.skin'\")\" = 0 ]")"
check "an ordinary page afterwards has no preview skin" "$(yes_if "[ \"\$(get dev-user /devices | links)\" = 0 ]")"
choose dev-user zerg
check "with a skin chosen, a preview of another skin still shows that other skin" "$(yes_if "get dev-user /plugin/theme-selector/preview/terran | grep -q 'skins/terran/skin.css' && ! get dev-user /plugin/theme-selector/preview/terran | grep -q 'skins/zerg/skin.css'")"
check "and the visitor's own pages still follow their choice" "$(yes_if "[ \"\$(skin_of dev-user)\" = zerg ]")"
choose dev-user ''

echo "== the picker page"
PAGE="$(get dev-user /plugin/theme-selector)"
check "offers instance default, stock and every bundled skin" "$(yes_if "for id in '' none $BUNDLED_LINE; do printf '%s' \"\$PAGE\" | grep -q \"<option value=\\\"\$id\\\"\" || exit 1; done")"
check "groups them (defaults, bundled)" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'optgroup label=\"Defaults\"' && printf '%s' \"\$PAGE\" | grep -q 'optgroup label=\"Bundled\"'")"
check "shows no preview until one is asked for" "$(yes_if "printf '%s' \"\$PAGE\" | tr -d '\n' | tr -s ' ' | grep -q 'id=\"ts-preview\" hidden'")"
check "a non-admin does not get the installed-skins list" "$(yes_if "! printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-skins\"'")"
PAGE="$(get dev-user '/plugin/theme-selector?preview=zerg')"
check "?preview=zerg previews zerg: frame source, selected option" "$(yes_if "printf '%s' \"\$PAGE\" | tr -d '\n' | grep -q 'src=\"[^\"]*/plugin/theme-selector/preview/zerg\"' && printf '%s' \"\$PAGE\" | grep -q '<option value=\"zerg\" selected'")"
check "the apply form names that skin and is a POST" "$(yes_if "printf '%s' \"\$PAGE\" | tr -d '\n' | grep -q 'name=\"skin\" id=\"ts-apply-value\" value=\"zerg\"'")"
check "the preview frame is not a link, and cannot be clicked" "$(yes_if "! printf '%s' \"\$PAGE\" | grep -q 'ts-iframe.*href='")"
PAGE="$(get dev-user '/plugin/theme-selector?preview=nope')"
check "?preview=<unknown> previews nothing" "$(yes_if "printf '%s' \"\$PAGE\" | tr -d '\n' | tr -s ' ' | grep -q 'id=\"ts-preview\" hidden'")"
PAGE="$(get dev-user '/plugin/theme-selector?preview=%3Cscript%3Ealert(1)%3C/script%3E')"
check "?preview=<markup> is not echoed" "$(yes_if "! printf '%s' \"\$PAGE\" | grep -q '<script>alert(1)'")"
PAGE="$(get dev-user '/plugin/theme-selector?preview[]=zerg')"
check "?preview[]=x (an array) is ignored, not an error" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-preview\"'")"
check "?preview= (instance default) previews stock when no default is set" "$(yes_if "get dev-user '/plugin/theme-selector?preview=' | tr -d '\n' | grep -q 'src=\"[^\"]*/preview/none\"'")"
q "insert into theme_selector_settings (name, value, created_at, updated_at) values ('default_skin', '\"terran\"', now(), now()) on duplicate key update value='\"terran\"'" >/dev/null
check "...and the instance default's skin once one is set" "$(yes_if "get dev-user '/plugin/theme-selector?preview=' | tr -d '\n' | grep -q 'src=\"[^\"]*/preview/terran\"'")"
q "delete from theme_selector_settings where name='default_skin'" >/dev/null

echo "== applying"
check "previewing does not apply" "$(yes_if "[ \"\$(skin_of dev-user)\" = none ]")"
choose dev-user protoss
check "the apply form's POST saves the skin" "$(yes_if "[ \"\$(skin_of dev-user)\" = protoss ]")"
PAGE="$(get dev-user '/plugin/theme-selector?preview=protoss')"
check "previewing your own skin says so, and the button is off" "$(yes_if "printf '%s' \"\$PAGE\" | tr -d '\n' | grep -q 'id=\"ts-apply\" disabled' && printf '%s' \"\$PAGE\" | grep -q 'This is your skin now'")"
PAGE="$(get dev-user '/plugin/theme-selector?preview=zerg')"
check "previewing another skin leaves the button on" "$(yes_if "! printf '%s' \"\$PAGE\" | tr -d '\n' | grep -q 'id=\"ts-apply\" disabled'")"
choose dev-user nonsense
check "applying an unknown skin changes nothing" "$(yes_if "[ \"\$(skin_of dev-user)\" = protoss ]")"
choose dev-user ''

echo "== the admin's list"
PAGE="$(get dev-admin /plugin/theme-selector)"
check "an admin gets the installed-skins list, sortable and filterable" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-skins\"' && printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-filter\"' && printf '%s' \"\$PAGE\" | grep -q 'class=\"ts-sort\" data-key=\"installed\"'")"
check "it has an Installed column, and a Preview link per skin" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q '>Installed<' && [ \"\$(printf '%s' \"\$PAGE\" | grep -c 'theme-selector?preview=')\" -ge 4 ]")"
check "every row carries its own sort keys" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | grep -c 'data-search=')\" = \"\$(echo \"$BUNDLED\" | wc -l)\" ]")"
check "the bundled skins say so" "$(yes_if "printf '%s' \"\$PAGE\" | tr -d '\n' | grep -q 'data-source=\"bundled\"'")"

echo
if [ $FAILED = 0 ]; then echo "all checks passed"; else echo "SOME CHECKS FAILED"; fi
exit $FAILED
