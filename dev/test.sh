#!/bin/sh
# Run the tests, safely.
#
#   sh dev/test.sh            unit tests, PHP lint, token catalog and TOKENS.md checks   (default)
#   sh dev/test.sh mutate     break each defence in turn; each must be caught
#   sh dev/test.sh live       end-to-end against the running dev instance
#   sh dev/test.sh all        unit, then mutation and live at the same time
#   sh dev/test.sh update     install, the nightly update and uninstall on a clean LibreNMS (its own stack, ~4 min; not part of `all`)
#
# Run from WSL or Linux with Docker. The unit and mutation runs execute in a
# throwaway container that is SEALED: the repository is mounted read-only, the
# root filesystem is read-only, and the only writable place is a RAM-backed
# /tmp (executable, because the release tests run a fake `gh` from it). Those
# suites include deliberately hostile inputs, and the mutation run
# executes deliberately broken code, so they must never be able to reach the
# repository or anything else. (One once did, through a symlink to "/", and
# deleted a bind-mounted copy of this repository. Hence the seal.)
#
# `live` needs the dev stack up (docker compose -f dev/compose.yml up -d). Its
# container mounts the repository read-only too.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="${TS_IMAGE:-theme-selector-dev-librenms}"
APP="${TS_CONTAINER:-theme-selector-dev-librenms-1}"

sealed() {
  docker run --rm --read-only --tmpfs /tmp:rw,exec,size=1g --entrypoint sh \
    -v "$ROOT:/plugin:ro" "$IMAGE" -c "$1"
}

unit() {
  echo "== PHP lint, unit tests, token catalog"
  sealed '
    bad=0
    for f in $(find /plugin/src /plugin/routes /plugin/database /plugin/tests /plugin/dev -name "*.php"); do
      php -l "$f" | grep -v "^No syntax errors" && bad=1
    done
    [ $bad = 0 ] && echo "lint: clean"
    php /plugin/tests/run.php
    python3 /plugin/scripts/gen-token-catalog.py --check
    python3 /plugin/scripts/gen-token-docs.py --check
    python3 /plugin/scripts/make-clock-tower.py --check && echo "Clock Tower skins are current (and every text colour is 4.5:1 or better)"
  '
}

mutate() {
  echo "== mutation check (sealed)"
  sealed 'sh /plugin/tests/mutate.sh'
}

live() {
  echo "== end to end, against $APP"
  TS_CONTAINER="$APP" sh "$ROOT/dev/test-patch.sh"
  docker exec "$APP" sh /plugin/dev/test-graphs.sh
  TS_CONTAINER="$APP" sh "$ROOT/dev/test-port-recolour.sh"
  docker exec "$APP" sh /plugin/dev/test-picker.sh
  docker exec "$APP" sh /plugin/dev/test-upload.sh
}

# Unit first (it is fast, and there is no point going on if it fails). Then the mutation check
# (sealed, in its own container, never touching the dev stack) and the live suites (which use
# the dev stack) are independent, so they run at the same time; the mutation output is held and
# printed after the live output so the two don't interleave.
all() {
  unit || return 1
  mfile="$(mktemp)"
  ( mutate > "$mfile" 2>&1 ) &
  mpid=$!
  lrc=0
  live || lrc=$?
  mrc=0
  wait "$mpid" || mrc=$?
  cat "$mfile"
  rm -f "$mfile"
  [ "$lrc" = 0 ] && [ "$mrc" = 0 ]
}

case "${1:-unit}" in
  unit) unit ;;
  mutate) mutate ;;
  live) live ;;
  all) all ;;
  update) sh "$ROOT/dev/test-update.sh" ;;
  *) echo "usage: sh dev/test.sh [unit|mutate|live|all|update]" >&2; exit 2 ;;
esac
