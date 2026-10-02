#!/bin/sh
# The picker, its preview and the two mode slots, end to end, against the dev instance: real routes,
# real sessions.
#
#   docker exec theme-selector-dev-librenms-1 sh /plugin/dev/test-picker.sh
#
# What it proves beyond the unit tests: that the preview page shows exactly the skin and mode it is
# asked for and nothing else changes (not the visitor's own skins, not any other page), that an id
# that is not an installed skin is a 404, that the picker offers the right choices for each mode and
# previews the right one, that applying is a separate, explicit POST that saves each mode on its
# own, and that a page carries one skin for each mode, each written for its own mode.
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
choose() { # user data
  tok="$(token "$1")"
  curl -s -o /dev/null -b "$(jar "$1")" -c "$(jar "$1")" -H "X-Dev-User: $1" --data "_token=$tok&$2" $B/plugin/theme-selector
}
meta() { get "$1" /devices | grep -o "<meta name=\"$2\" content=\"[^\"]*\"" | cut -d'"' -f4; } # user meta-name
links() { grep -c 'data-theme-selector'; }
squash() { tr -d '\n' | tr -s ' '; }

echo "== setup"
q "delete from users_prefs where pref like 'theme_selector.%'" >/dev/null
q "delete from theme_selector_settings where name like 'default_skin%'" >/dev/null
login dev-admin; login dev-user
BUNDLED="$(ls -1 /plugin/skins)"
BUNDLED_LINE="$(echo $BUNDLED)"
DARK_SKIN=terran
LIGHT_SKIN=""
for id in $BUNDLED_LINE; do
  [ "$(grep -o '"mode": *"[a-z]*"' /plugin/skins/$id/skin.json | grep -o 'light')" = light ] && LIGHT_SKIN="${LIGHT_SKIN:-$id}"
done

echo "== the preview page"
ROUTE="$(cd /opt/librenms && gosu librenms php artisan route:list --path=plugin/theme-selector/preview --json 2>/dev/null)"
# (The dev instance logs anonymous requests in as dev-admin, so a request can't be made without
# a user here; the route's middleware says what guards it.)
check "needs a login (the route sits behind web + auth, and is not admin-only)" "$(yes_if "printf '%s' '$ROUTE' | grep -q 'Authenticate' && ! printf '%s' '$ROUTE' | grep -qi 'can:admin\|Authorize'")"
for id in $BUNDLED_LINE; do
  native="$(grep -o '"mode": *"[a-z]*"' /plugin/skins/$id/skin.json | grep -o 'light\|dark')"; native="${native:-dark}"
  other=light; [ "$native" = light ] && other=dark
  for mode in dark light; do
    PAGE="$(get dev-user "/plugin/theme-selector/preview/$id?mode=$mode")"
    base=base.css; [ "$mode" = light ] && base=base-light.css
    file=skin.css; [ "$native" != "$mode" ] && file=skin.mirror.css
    check "$id in $mode mode: that mode's base and the skin's stylesheet for it ($file), and nothing else" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | links)\" = 2 ] && printf '%s' \"\$PAGE\" | grep -q \"theme-selector/$base\" && printf '%s' \"\$PAGE\" | grep -q \"skins/$id/$file\"")"
    check "$id in $mode mode: the page is put in that mode" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q \"classList.toggle('dark', $([ $mode = dark ] && echo true || echo false))\"")"
    check "$id in $mode mode: sample content, a sample graph, no rabbit" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'port_bits' && printf '%s' \"\$PAGE\" | grep -q 'viewBox=\"0 0 480 130\"' && ! printf '%s' \"\$PAGE\" | grep -q 'ts-white-rabbit'")"
  done
done
PAGE="$(get dev-user '/plugin/theme-selector/preview/none?mode=light')"
check "none: stock, no skin links, both meta tags say so" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | links)\" = 0 ] && [ \"\$(printf '%s' \"\$PAGE\" | grep -c 'content=\"none\"')\" = 2 ]")"
check "digital-rain asks for the ornament layer in whichever slot it is shown (dark mark, light mark)" "$(yes_if "get dev-user '/plugin/theme-selector/preview/digital-rain?mode=dark' | grep -q 'data-ts-orn>' ; get dev-user '/plugin/theme-selector/preview/digital-rain?mode=light' | grep -q 'data-ts-orn-light'")"
check "a mode that is not one shows dark" "$(yes_if "get dev-user '/plugin/theme-selector/preview/zerg?mode=sepia' | grep -q 'theme-selector/base.css'")"
for bad in nope BAD 'a%2Fb' '..%2F..%2Fetc' 'zerg%0A' '.hidden' ''; do
  check "an id that is not an installed skin is refused: '$bad'" "$(yes_if "[ \"\$(code dev-user '/plugin/theme-selector/preview/$bad')\" != 200 ]")"
done
check "a query string cannot change the skin shown ('?skin=terran' on the zerg preview)" "$(yes_if "get dev-user '/plugin/theme-selector/preview/zerg?skin=terran&dark=terran&preview=terran' | grep -q 'skins/zerg/skin.css' && ! get dev-user '/plugin/theme-selector/preview/zerg?skin=terran' | grep -q 'skins/terran/skin.css'")"

echo "== looking does not change anything"
check "the visitor's own skins are unchanged after previews" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = none ] && [ \"\$(meta dev-user theme-selector-light)\" = none ]")"
check "no preference was saved" "$(yes_if "[ \"\$(q \"select count(*) from users_prefs where pref like 'theme_selector.%'\")\" = 0 ]")"
check "an ordinary page afterwards has no preview skin" "$(yes_if "[ \"\$(get dev-user /devices | links)\" = 0 ]")"
choose dev-user skin=zerg
check "with a skin chosen, a preview of another still shows that other one" "$(yes_if "get dev-user /plugin/theme-selector/preview/terran | grep -q 'skins/terran/skin.css' && ! get dev-user /plugin/theme-selector/preview/terran | grep -q 'skins/zerg/skin.css'")"
check "and the visitor's own pages still follow their choice" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = zerg ]")"
choose dev-user skin=

echo "== the picker page: one dropdown and one preview for each mode"
PAGE="$(get dev-user /plugin/theme-selector)"
check "has a light-mode and a dark-mode dropdown, each with its own field" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'name=\"skin_light\"' && printf '%s' \"\$PAGE\" | grep -q 'name=\"skin\"'")"
check "and two preview frames" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | grep -c 'class=\"ts-iframe\"')\" = 2 ]")"
check "there is no Preview button any more" "$(yes_if "! printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-show\"'")"
check "each dropdown offers instance default, stock and every bundled skin" "$(yes_if "for f in skin_light skin; do for id in '' none $BUNDLED_LINE; do printf '%s' \"\$PAGE\" | tr -d '\n' | tr -s ' ' | sed 's/<select/\n<select/g' | grep \"name=\\\"\$f\\\"\" | grep -q \"<option value=\\\"\$id\\\"\" || exit 1; done; done")"
check "a skin written for the other mode is labelled as such" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q '(written for dark)'")"
check "the frames start on the visitor's own choices (stock, with no default set)" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | grep -c 'preview/none?mode=')\" -ge 4 ]")"
check "a non-admin does not get the installed-skins list or the defaults" "$(yes_if "! printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-skins\"' && ! printf '%s' \"\$PAGE\" | grep -q 'name=\"default_light\"'")"
PAGE="$(get dev-user '/plugin/theme-selector?dark=zerg&light=terran')"
check "?dark=zerg&light=terran selects them and points the frames at them" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q '<option value=\"zerg\" selected' && printf '%s' \"\$PAGE\" | grep -q '<option value=\"terran\" selected' && printf '%s' \"\$PAGE\" | grep -q 'preview/zerg?mode=dark' && printf '%s' \"\$PAGE\" | grep -q 'preview/terran?mode=light'")"
PAGE="$(get dev-user '/plugin/theme-selector?dark=nope&light=%3Cscript%3Ealert(1)%3C/script%3E')"
check "an unknown or hostile choice is ignored and not echoed" "$(yes_if "! printf '%s' \"\$PAGE\" | grep -q '<script>alert(1)' && ! printf '%s' \"\$PAGE\" | grep -q 'nope'")"
PAGE="$(get dev-user '/plugin/theme-selector?dark[]=zerg')"
check "an array is ignored, not an error" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'class=\"ts-iframe\"'")"
q "insert into theme_selector_settings (name, value, created_at, updated_at) values ('default_skin', '\"terran\"', now(), now()), ('default_skin_light', '\"zerg\"', now(), now()) on duplicate key update value=values(value)" >/dev/null
PAGE="$(get dev-user /plugin/theme-selector)"
check "with defaults set, the instance-default entries name them and the frames show them" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'Instance default (Terran)' && printf '%s' \"\$PAGE\" | grep -q 'Instance default (Zerg)' && printf '%s' \"\$PAGE\" | grep -q 'preview/terran?mode=dark' && printf '%s' \"\$PAGE\" | grep -q 'preview/zerg?mode=light'")"
q "delete from theme_selector_settings where name like 'default_skin%'" >/dev/null

echo "== applying: each mode on its own"
check "previewing does not apply" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = none ]")"
choose dev-user skin=protoss
check "posting the dark choice saves the dark skin only" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = protoss ] && [ \"\$(meta dev-user theme-selector-light)\" = none ]")"
choose dev-user skin_light=terran
check "posting the light choice saves the light skin only, and leaves the dark one" "$(yes_if "[ \"\$(meta dev-user theme-selector-light)\" = terran ] && [ \"\$(meta dev-user theme-selector)\" = protoss ]")"
check "the preferences are stored under their own names (the dark one keeps its original)" "$(yes_if "[ \"\$(q \"select value from users_prefs where pref='theme_selector.skin' and user_id=(select user_id from users where username='dev-user')\")\" = protoss ] && [ \"\$(q \"select value from users_prefs where pref='theme_selector.skin_light' and user_id=(select user_id from users where username='dev-user')\")\" = terran ]")"
choose dev-user "skin=zerg&skin_light=nonsense"
check "one bad choice changes nothing, not even the good one" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = protoss ] && [ \"\$(meta dev-user theme-selector-light)\" = terran ]")"
choose dev-user "skin=zerg&skin_light=none"
check "both together, with stock for light" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = zerg ] && [ \"\$(meta dev-user theme-selector-light)\" = none ]")"
choose dev-user "skin[]=zerg"
check "an array is refused and changes nothing" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = zerg ]")"
choose dev-user "skin=&skin_light="

echo "== a page carries one skin for each mode"
choose dev-user "skin=zerg&skin_light=terran"
PAGE="$(get dev-user /devices)"
check "four stylesheets: both bases, and each slot's skin" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | links)\" = 4 ]")"
check "the dark slot loads the dark base and zerg's own stylesheet" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'theme-selector/base.css' && printf '%s' \"\$PAGE\" | grep -q 'skins/zerg/skin.css'")"
check "the light slot loads the light base and terran's mirror (terran is written for dark)" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'theme-selector/base-light.css' && printf '%s' \"\$PAGE\" | grep -q 'skins/terran/skin.mirror.css'")"
check "the files they name are real stylesheets with the right selector" "$(yes_if "curl -s $B/css/custom/theme-selector/skins/zerg/skin.css | grep -q '^html.dark {' && curl -s $B/css/custom/theme-selector/skins/terran/skin.mirror.css | grep -q '^html:not(.dark) {' && ! curl -s $B/css/custom/theme-selector/skins/terran/skin.mirror.css | grep -q 'html.dark'")"
STRIP="awk '/ts:dark-only:/{f=1} !f{print} /ts:end-dark-only/{f=0}'"
check "the light base is the dark base with the selector swapped (minus the dark map), and the light-only mapping on the end" "$(yes_if "BASE=\$(curl -s $B/css/custom/theme-selector/base.css | $STRIP); N=\$(printf '%s\n' \"\$BASE\" | wc -l); LIGHT=\$(curl -s $B/css/custom/theme-selector/base-light.css); [ \"\$(printf '%s\n' \"\$BASE\" | sed 's/html\.dark:has(link\[data-ts-orn\])/X/g; s/html\.dark/X/g' | md5sum)\" = \"\$(printf '%s\n' \"\$LIGHT\" | head -n \$N | sed 's/html:not(\.dark):has(link\[data-ts-orn-light\])/X/g; s/html:not(\.dark)/X/g' | md5sum)\" ] && printf '%s\n' \"\$LIGHT\" | tail -n +\$((N+1)) | grep -q 'tw-color-gray-100' && ! printf '%s\n' \"\$LIGHT\" | grep -q 'leaflet-tile'")"
choose dev-user "skin=terran&skin_light=none"
PAGE="$(get dev-user /devices)"
check "only the dark slot set: the dark base and terran, and nothing for light" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | links)\" = 2 ] && printf '%s' \"\$PAGE\" | grep -q 'theme-selector/base.css' && ! printf '%s' \"\$PAGE\" | grep -q 'base-light'")"
choose dev-user "skin=none&skin_light=zerg"
PAGE="$(get dev-user /devices)"
check "only the light slot set: the light base and the skin's mirror" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | links)\" = 2 ] && printf '%s' \"\$PAGE\" | grep -q 'base-light.css' && ! printf '%s' \"\$PAGE\" | grep -q 'theme-selector/base.css'")"
check "?theme-selector=off shows neither" "$(yes_if "[ \"\$(get dev-user '/devices?theme-selector=off' | links)\" = 0 ]")"
choose dev-user "skin=&skin_light="

echo "== the instance defaults, one for each mode"
tok="$(token dev-admin)"
curl -s -o /dev/null -b "$(jar dev-admin)" -c "$(jar dev-admin)" -H "X-Dev-User: dev-admin" --data "_token=$tok&default=terran&default_light=zerg" $B/plugin/theme-selector/default
check "both are saved" "$(yes_if "[ \"\$(q \"select value from theme_selector_settings where name='default_skin'\")\" = '\"terran\"' ] && [ \"\$(q \"select value from theme_selector_settings where name='default_skin_light'\")\" = '\"zerg\"' ]")"
check "a user with no choice follows them, one for each mode" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = terran ] && [ \"\$(meta dev-user theme-selector-light)\" = zerg ]")"
curl -s -o /dev/null -b "$(jar dev-admin)" -c "$(jar dev-admin)" -H "X-Dev-User: dev-admin" --data "_token=$tok&default_light=" $B/plugin/theme-selector/default
check "clearing one leaves the other" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = terran ] && [ \"\$(meta dev-user theme-selector-light)\" = none ]")"
curl -s -o /dev/null -b "$(jar dev-admin)" -c "$(jar dev-admin)" -H "X-Dev-User: dev-admin" --data "_token=$tok&default=terran&default_light=nope" $B/plugin/theme-selector/default
check "an unknown default is refused and changes nothing" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = terran ] && [ \"\$(meta dev-user theme-selector-light)\" = none ]")"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$(jar dev-user)" -H 'X-Dev-User: dev-user' --data "_token=$(token dev-user)&default=zerg" $B/plugin/theme-selector/default)"
check "a non-admin cannot set a default (403)" "$(yes_if "[ '$CODE' = 403 ]")"
curl -s -o /dev/null -b "$(jar dev-admin)" -c "$(jar dev-admin)" -H "X-Dev-User: dev-admin" --data "_token=$tok&default=&default_light=" $B/plugin/theme-selector/default
check "both cleared" "$(yes_if "[ \"\$(meta dev-user theme-selector)\" = none ] && [ \"\$(meta dev-user theme-selector-light)\" = none ]")"

echo "== the admin's list"
PAGE="$(get dev-admin /plugin/theme-selector)"
check "an admin gets the installed-skins list, sortable and filterable (by mode too)" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-skins\"' && printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-filter\"' && printf '%s' \"\$PAGE\" | grep -q 'id=\"ts-mode\"' && printf '%s' \"\$PAGE\" | grep -q 'class=\"ts-sort\" data-key=\"installed\"'")"
check "it has Mode and Installed columns, and a Preview link per skin in the skin's own mode" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q '>Mode<' && printf '%s' \"\$PAGE\" | grep -q '>Installed<' && [ \"\$(printf '%s' \"\$PAGE\" | grep -c 'theme-selector?dark=\|theme-selector?light=')\" -ge 4 ]")"
check "every row carries its own sort keys and its mode" "$(yes_if "[ \"\$(printf '%s' \"\$PAGE\" | grep -c 'data-search=')\" = \"\$(echo \"$BUNDLED\" | wc -l)\" ] && printf '%s' \"\$PAGE\" | tr -d '\n' | grep -q 'data-mode=\"dark\"'")"
check "an admin gets a default for each mode" "$(yes_if "printf '%s' \"\$PAGE\" | grep -q 'name=\"default_light\"' && printf '%s' \"\$PAGE\" | grep -q 'name=\"default\"'")"

echo
if [ $FAILED = 0 ]; then echo "all checks passed"; else echo "SOME CHECKS FAILED"; fi
exit $FAILED
