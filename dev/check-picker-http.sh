#!/bin/sh
# Run only in the disposable clean test container. Exercise real login and
# require the picker itself, not the unrelated public /login page, to render.
set -eu
base=http://127.0.0.1:8000
jar=$(mktemp)
page=$(mktemp)
trap 'rm -f "$jar" "$page"' EXIT
curl -fsS -c "$jar" "$base/login" > "$page"
token=$(grep -o 'name="_token" value="[^"]*"' "$page" | head -1 | cut -d '"' -f 4)
[ -n "$token" ] || { echo 'Login form has no CSRF token' >&2; exit 1; }
curl -fsS -o /dev/null -b "$jar" -c "$jar" \
  --data-urlencode "_token=$token" --data-urlencode 'username=tester' \
  --data-urlencode 'password=theme-selector-test-only' "$base/login"
status=$(curl -sS -o "$page" -w '%{http_code}' -b "$jar" "$base/plugin/theme-selector")
[ "$status" = 200 ] && grep -q 'id="ts-picker"' "$page" || {
  echo "Authenticated picker did not render (HTTP $status)" >&2
  exit 1
}
