#!/bin/sh
# Run in the localhost-only development instance. No update is installed.
set -eu
b=http://127.0.0.1:8000
admin=/tmp/ts-update-admin.jar
user=/tmp/ts-update-user.jar
page=/tmp/ts-update-access.html
trap 'rm -f "$admin" "$user" "$page"' EXIT

get() { curl -fsS -b "$2" -c "$2" -H "X-Dev-User: $1" "$b/plugin/theme-selector" > "$page"; }
post() {
    token=$(grep -o 'name="_token" value="[^"]*"' "$page" | head -1 | cut -d '"' -f4)
    [ -n "$token" ]
    curl -sS -o /dev/null -w '%{http_code}' -b "$2" -c "$2" -H "X-Dev-User: $1" --data-urlencode "_token=$token" "$b/plugin/theme-selector/update/$3"
}
rm -f "$admin" "$user"
get dev-user "$user"
! grep -q 'id="ts-update"' "$page"
[ "$(post dev-user "$user" check)" = 403 ]
[ "$(post dev-user "$user" install)" = 403 ]
get dev-admin "$admin"
grep -q 'Check for update' "$page"
grep -q 'class="btn btn-primary" disabled>Install update' "$page"
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$admin" -H 'X-Dev-User: dev-admin' -X POST "$b/plugin/theme-selector/update/check")" = 419 ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$admin" -H 'X-Dev-User: dev-admin' -X POST "$b/plugin/theme-selector/update/install")" = 419 ]
[ "$(post dev-admin "$admin" install)" = 302 ]
get dev-admin "$admin"
grep -q 'Check for updates again' "$page"
echo 'update authorization, CSRF and unchecked installation passed'
