#!/bin/sh
# Runs inside the disposable clean rehearsal. Its git repository is synthetic.
set -eu
b=http://127.0.0.1:8000
jar=$(mktemp)
page=$(mktemp)
trap 'rm -f "$jar" "$page"' EXIT

login() {
    rm -f "$jar"
    curl -fsS -c "$jar" "$b/login" > "$page"
    token=$(grep -o 'name="_token" value="[^"]*"' "$page" | head -1 | cut -d '"' -f 4)
    [ -n "$token" ]
    curl -fsS -o /dev/null -b "$jar" -c "$jar" --data-urlencode "_token=$token" \
        --data-urlencode 'username=tester' --data-urlencode 'password=theme-selector-test-only' "$b/login"
}
get_picker() { curl -fsS -b "$jar" -c "$jar" "$b/plugin/theme-selector" > "$page"; }
post() {
    token=$(grep -o 'name="_token" value="[^"]*"' "$page" | head -1 | cut -d '"' -f 4)
    [ -n "$token" ]
    code=$(curl -sS --max-time 650 -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" \
        --data-urlencode "_token=$token" "$b/plugin/theme-selector/update/$1")
    [ "$code" = 302 ] || { echo "$1 returned HTTP $code" >&2; exit 1; }
}

login
get_picker
grep -q 'Check for update' "$page"
post check
get_picker
grep -q 'An update is available:' "$page" || { echo 'The update was not offered' >&2; exit 1; }
grep -q 'class="btn btn-primary" >Install update' "$page" || { echo 'Install button was not enabled' >&2; exit 1; }
post install
# Composer may clear Redis sessions. A normal user must log back in before
# seeing the persisted, non-sensitive result.
login
get_picker
grep -Fq 'The update completed. The installed version may take a moment to appear.' "$page" || {
    echo 'The update outcome was not shown after login' >&2; exit 1;
}
echo 'update check, install and result after re-login passed'
