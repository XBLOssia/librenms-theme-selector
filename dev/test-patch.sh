#!/bin/sh
# Tests of the optional core patch and of the update wrapper:
#
#   scripts/patch-core.sh     apply / revert / status
#   scripts/daily-wrapper.sh  daily.sh with the patch out of the way
#   src/GraphPalette.php      writes the port keys when the patched helper is in place
#
#   sh dev/test-patch.sh          (run from the host, in WSL or Linux; needs git, patch and docker)
#
# Part 1 never touches a real LibreNMS: it copies the dev container's real
# generic_data.inc.php and config_definitions.json into a throwaway git repository under
# /tmp and runs the scripts there, including the failure this exists for (a `git pull`
# that stops because upstream edited the patched file). Part 2 needs the dev stack: it
# swaps the patched helper into the container for a moment, checks what the plugin
# writes with and without it, and restores the original file.
set -u

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
APP="${TS_CONTAINER:-theme-selector-dev-librenms-1}"
PATCHER="$ROOT/scripts/patch-core.sh"
WRAP="$ROOT/scripts/daily-wrapper.sh"
W="$(mktemp -d /tmp/ts-patch-test.XXXXXX)"
case "$W" in /tmp/ts-patch-test.*) ;; *) echo "refusing to run: scratch dir is $W"; exit 1 ;; esac
cleanup() {
  if [ -n "${ORIG_HELPER:-}" ] && [ -f "$ORIG_HELPER" ]; then
    docker cp "$ORIG_HELPER" "$APP:/opt/librenms/includes/html/graphs/generic_data.inc.php" >/dev/null 2>&1
    docker exec "$APP" chown librenms:librenms /opt/librenms/includes/html/graphs/generic_data.inc.php >/dev/null 2>&1
  fi
  rm -rf "$W"
}
trap cleanup EXIT

FAILED=0
check() { if [ "$2" = ok ]; then echo "  ok    $1"; else echo "  FAIL  $1"; FAILED=1; fi; }
yes_if() { if eval "$1"; then echo ok; else echo no; fi; }

HELPER=includes/html/graphs/generic_data.inc.php
DEFS=resources/definitions/config_definitions.json
docker cp "$APP:/opt/librenms/$HELPER" "$W/helper.orig" >/dev/null 2>&1 || { echo "cannot read the dev container's $HELPER (is the stack up?)"; exit 1; }
docker cp "$APP:/opt/librenms/$DEFS" "$W/defs.orig" >/dev/null 2>&1 || { echo "cannot read $DEFS"; exit 1; }
ORIG_HELPER="$W/helper.orig"
SUM() { sha256sum "$1" | cut -d' ' -f1; }

# A fresh "LibreNMS" git checkout at $W/core, with the two real files committed.
mkrepo() {
  rm -rf "$W/core"; mkdir -p "$W/core/includes/html/graphs" "$W/core/resources/definitions"
  cp "$W/helper.orig" "$W/core/$HELPER"; cp "$W/defs.orig" "$W/core/$DEFS"
  (cd "$W/core" && git init -q -b main . && git config user.email t@example.org && git config user.name t \
    && git add -A && git commit -q -m stock) >/dev/null 2>&1
}
C="$W/core"
P() { sh "$PATCHER" "$@" --librenms "$C"; }
# an "upstream" commit that edits the helper somewhere else (the patch still applies, with an offset)
upstream_elsewhere() {
  (cd "$C" && git checkout -q -b upstream main \
    && sed -i '0,/^<?php$/s//<?php\n\/\/ upstream edit: a new comment near the top/' "$HELPER" \
    && git commit -q -am "upstream edits elsewhere in the file" && git checkout -q main) >/dev/null 2>&1
}
# an "upstream" commit that rewrites the very lines the patch changes
upstream_in_the_patched_region() {
  (cd "$C" && git checkout -q -b upstream main \
    && sed -i "s/#90B040/#91B141/" "$HELPER" && git commit -q -am "upstream recolours the series" && git checkout -q main) >/dev/null 2>&1
}
# a stand-in for daily.sh: what it does that matters here is `git pull`
cat > "$W/daily.sh" <<EOF
#!/bin/sh
git -C "$C" merge --ff-only upstream
EOF
cat > "$W/daily-fails.sh" <<EOF
#!/bin/sh
exit 7
EOF
chmod +x "$W/daily.sh" "$W/daily-fails.sh"

echo "== apply, status, revert"
mkrepo
P status > "$W/out" 2>&1
check "before: status says NOT APPLIED" "$(yes_if "grep -q '^NOT APPLIED' '$W/out'")"
P apply > "$W/out" 2>&1; rc=$?
check "apply succeeds" "$(yes_if "[ $rc = 0 ] && grep -q 'patch applied' '$W/out'")"
check "it patched exactly one file" "$(yes_if "[ \"\$(cd '$C' && git status --porcelain)\" = ' M $HELPER' ]")"
check "the defaults in the patched helper are the colours it replaced" "$(yes_if "grep -q \"'D7FFC7', '90B040', '608720'\" '$C/$HELPER' && grep -q \"'E0E0FF', '8080C0', '606090'\" '$C/$HELPER'")"
check "no backup copy was left behind" "$(yes_if "[ -z \"\$(find '$C' -name '*.pre-skins-patch' -o -name '*.orig' -o -name '*.rej')\" ]")"
P status > "$W/out" 2>&1
check "status says APPLIED and points at the wrapper" "$(yes_if "grep -q '^APPLIED' '$W/out' && grep -q 'daily-wrapper.sh' '$W/out'")"
P apply > "$W/out" 2>&1
check "applying twice is a no-op" "$(yes_if "grep -q 'Already applied' '$W/out'")"
P revert > "$W/out" 2>&1; rc=$?
check "revert succeeds" "$(yes_if "[ $rc = 0 ] && grep -q 'patch reversed' '$W/out'")"
check "and the helper is byte-for-byte the original" "$(yes_if "[ \"\$(SUM '$C/$HELPER')\" = \"\$(SUM '$W/helper.orig')\" ] && [ -z \"\$(cd '$C' && git status --porcelain)\" ]")"
P revert > "$W/out" 2>&1
check "reverting twice is a no-op" "$(yes_if "grep -q 'Not applied' '$W/out'")"
P apply --dry-run > "$W/out" 2>&1
check "--dry-run changes nothing" "$(yes_if "[ -z \"\$(cd '$C' && git status --porcelain)\" ]")"

echo "== the failure this exists for"
mkrepo; upstream_elsewhere
P apply >/dev/null 2>&1
(cd "$C" && git merge --ff-only upstream) > "$W/out" 2>&1; rc=$?
check "with the patch applied, a git pull-style merge of an upstream edit STOPS" "$(yes_if "[ $rc != 0 ] && grep -qi 'overwritten' '$W/out'")"

echo "== the wrapper"
mkrepo; upstream_elsewhere
P apply >/dev/null 2>&1
DAILY_SH="$W/daily.sh" LIBRENMS="$C" sh "$WRAP" > "$W/out" 2>&1; rc=$?
check "the update goes through (exit status 0)" "$(yes_if "[ $rc = 0 ]")"
check "the helper has upstream's edit" "$(yes_if "grep -q 'upstream edit: a new comment' '$C/$HELPER'")"
check "and the patch is back" "$(yes_if "grep -q 'graph_colours.port_in' '$C/$HELPER'")"
check "HEAD is upstream's commit" "$(yes_if "[ \"\$(git -C '$C' rev-parse HEAD)\" = \"\$(git -C '$C' rev-parse upstream)\" ]")"
check "the only local change is the patch" "$(yes_if "[ \"\$(cd '$C' && git status --porcelain)\" = ' M $HELPER' ]")"
P revert >/dev/null 2>&1
check "reverting after the update gives upstream's file, not a stale copy" "$(yes_if "grep -q 'upstream edit: a new comment' '$C/$HELPER' && ! grep -q 'graph_colours.port_in' '$C/$HELPER' && [ -z \"\$(cd '$C' && git status --porcelain)\" ]")"

mkrepo; upstream_elsewhere
DAILY_SH="$W/daily.sh" LIBRENMS="$C" sh "$WRAP" > "$W/out" 2>&1; rc=$?
check "patch not applied beforehand: the update goes through and it is still not applied" "$(yes_if "[ $rc = 0 ] && ! grep -q 'graph_colours.port_in' '$C/$HELPER' && [ -z \"\$(cd '$C' && git status --porcelain)\" ]")"

mkrepo; P apply >/dev/null 2>&1
DAILY_SH="$W/daily-fails.sh" LIBRENMS="$C" sh "$WRAP" > "$W/out" 2>&1; rc=$?
check "when daily.sh fails, the wrapper passes its exit status on (7)" "$(yes_if "[ $rc = 7 ]")"
check "and the patch is back anyway" "$(yes_if "grep -q 'graph_colours.port_in' '$C/$HELPER'")"

mkrepo; upstream_in_the_patched_region; P apply >/dev/null 2>&1
DAILY_SH="$W/daily.sh" LIBRENMS="$C" sh "$WRAP" > "$W/out" 2>&1; rc=$?
check "upstream rewrote the patched lines: the update still goes through" "$(yes_if "[ $rc = 0 ] && grep -q '#91B141' '$C/$HELPER'")"
check "the wrapper says the patch could not be re-applied, and what to do" "$(yes_if "grep -q 'could not be re-applied' '$W/out' && grep -q 'patch-core.sh apply' '$W/out'")"
check "nothing half-patched is left (no marker, no .rej or .orig)" "$(yes_if "! grep -q 'graph_colours.port_in' '$C/$HELPER' && [ -z \"\$(find '$C' -name '*.rej' -o -name '*.orig')\" ] && [ -z \"\$(cd '$C' && git status --porcelain)\" ]")"
P apply > "$W/out" 2>&1; rc=$?
check "apply on a file it no longer fits fails loudly and changes nothing" "$(yes_if "[ $rc != 0 ] && grep -q 'does not apply cleanly' '$W/out' && [ -z \"\$(cd '$C' && git status --porcelain)\" ]")"

echo "== leftovers from the earlier versions"
mkrepo
cp "$W/helper.orig" "$C/$HELPER.pre-skins-patch"; echo "stale" >> "$C/$HELPER.pre-skins-patch"
(cd "$C" && git checkout -q -b upstream main && sed -i '0,/^<?php$/s//<?php\n\/\/ upstream edit/' "$HELPER" && git commit -q -am up && git checkout -q main) >/dev/null 2>&1
(cd "$C" && git merge -q --ff-only upstream) >/dev/null 2>&1
P apply >/dev/null 2>&1; P revert >/dev/null 2>&1
check "a stale *.pre-skins-patch is never restored over the file, and is removed" "$(yes_if "grep -q 'upstream edit' '$C/$HELPER' && [ ! -e '$C/$HELPER.pre-skins-patch' ]")"

mkrepo
(cd "$C" && patch -p1 -s < "$ROOT/patches/legacy-0001-config_definitions-declare-port-keys.patch") >/dev/null 2>&1
check "(setup) the old declaration is in place" "$(yes_if "grep -q 'graph_colours.port_in' '$C/$DEFS'")"
P status > "$W/out" 2>&1
check "status warns about it" "$(yes_if "grep -q 'WARNING' '$W/out' && grep -q 'old port-key declaration' '$W/out'")"
P apply > "$W/out" 2>&1; rc=$?
check "apply removes it and applies the one-file patch" "$(yes_if "[ $rc = 0 ] && ! grep -q 'graph_colours.port_in' '$C/$DEFS' && [ \"\$(SUM '$C/$DEFS')\" = \"\$(SUM '$W/defs.orig')\" ] && grep -q 'graph_colours.port_in' '$C/$HELPER'")"
# The incident: the earlier two-file patch was applied, and upstream then edited config_definitions.json.
mkrepo
(cd "$C" && patch -p1 -s < "$ROOT/patches/legacy-0001-config_definitions-declare-port-keys.patch") >/dev/null 2>&1
(cd "$C" && git stash -q && git checkout -q -b upstream main && sed -i '1a\ ' "$DEFS" && git commit -q -am "upstream adds lines to config_definitions.json" && git checkout -q main && git stash pop -q) >/dev/null 2>&1
(cd "$C" && git merge --ff-only upstream) > "$W/out" 2>&1; rc=$?
check "(setup) with the old declaration in place, the pull stops on config_definitions.json" "$(yes_if "[ $rc != 0 ] && grep -qi 'overwritten' '$W/out'")"
DAILY_SH="$W/daily.sh" LIBRENMS="$C" sh "$WRAP" > "$W/out" 2>&1; rc=$?
check "the wrapper removes the old declaration first, so the pull goes through" "$(yes_if "[ $rc = 0 ] && [ \"\$(git -C '$C' rev-parse HEAD)\" = \"\$(git -C '$C' rev-parse upstream)\" ]")"
check "and afterwards only the one-file patch is applied" "$(yes_if "! grep -q 'graph_colours.port_in' '$C/$DEFS' && grep -q 'graph_colours.port_in' '$C/$HELPER' && [ \"\$(cd '$C' && git status --porcelain)\" = ' M $HELPER' ]")"

echo "== revert when the patch no longer reverses cleanly"
mkrepo; P apply >/dev/null 2>&1
sed -i "s/'D7FFC7', '90B040', '608720'/'D7FFC7', 'AAAAAA', '608720'/" "$C/$HELPER"
P revert > "$W/out" 2>&1; rc=$?
check "it falls back to git checkout, and says so" "$(yes_if "[ $rc = 0 ] && grep -q 'restoring the file from git' '$W/out' && [ -z \"\$(cd '$C' && git status --porcelain)\" ]")"

echo "== the plugin writes the port keys only when the patched helper is in place"
if docker exec "$APP" true >/dev/null 2>&1; then
  docker cp "$ROOT/dev/probe-palette.php" "$APP:/tmp/probe-palette.php" >/dev/null 2>&1
  probe() { # skin -> the PROBE line
    docker exec "$APP" sh -c "cd /opt/librenms && gosu librenms env SKIN='$1' php artisan tinker --execute=\"require '/tmp/probe-palette.php';\"" 2>&1 | grep '^PROBE '
  }
  probe null > /dev/null
  out="$(probe protoss)"
  check "without the patch, protoss's port_in / port_out are NOT written (nothing reads them)" "$(yes_if "printf '%s' '$out' | grep -q '\"port_applied\":\[\]' && printf '%s' '$out' | grep -q '\"rows\":\[\]'")"
  probe null > /dev/null

  mkrepo; P apply >/dev/null 2>&1
  docker cp "$C/$HELPER" "$APP:/opt/librenms/$HELPER" >/dev/null 2>&1
  out="$(probe protoss)"
  check "with the patched helper, both keys are written, though LibreNMS does not declare them" "$(yes_if "printf '%s' '$out' | grep -q '\"port_applied\":\[\"graph_colours.port_in\",\"graph_colours.port_out\"\]'")"
  check "and the config table holds protoss's ramps" "$(yes_if "printf '%s' '$out' | grep -q '9CF7DC' && printf '%s' '$out' | grep -q 'FFE7A8'")"
  out="$(probe null)"
  check "clearing the default erases them again (they had no override before)" "$(yes_if "printf '%s' '$out' | grep -q '\"rows\":\[\]'")"

  docker cp "$W/helper.orig" "$APP:/opt/librenms/$HELPER" >/dev/null 2>&1
  docker exec "$APP" chown librenms:librenms "/opt/librenms/$HELPER" >/dev/null 2>&1
  check "the container's helper is back to stock" "$(yes_if "[ \"\$(docker exec $APP grep -c graph_colours.port_in /opt/librenms/$HELPER)\" = 0 ]")"
else
  echo "  (skipped: the dev container is not running)"
fi

echo
if [ $FAILED = 0 ]; then echo "all checks passed"; else echo "SOME CHECKS FAILED"; fi
exit $FAILED
