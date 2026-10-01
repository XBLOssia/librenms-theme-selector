#!/bin/sh
# Run LibreNMS's daily.sh with the port-graph core patch out of the way.
#
#   scripts/daily-wrapper.sh [daily.sh arguments]
#
# WHY
# With includes/html/graphs/generic_data.inc.php patched (scripts/patch-core.sh), a
# daily.sh `git pull` that finds upstream changes to that file STOPS: "Your local
# changes ... would be overwritten by merge". LibreNMS then silently stops
# updating, security fixes included, until someone notices. This wrapper:
#
#   1. reverses the patch (and the old config_definitions.json declaration, if an
#      earlier version of the patch left one),
#   2. runs daily.sh with whatever arguments it was given,
#   3. re-applies the patch afterwards, from an EXIT trap, so that happens even if
#      daily.sh fails or is interrupted.
#
# The exit status is daily.sh's. If the patch was not applied to begin with, it is
# not applied afterwards (the patch stays opt-in). If it no longer applies because
# upstream changed the file, the update has still happened; the wrapper says so on
# stderr and port graphs go back to stock colours until the patch is regenerated.
#
# USE IT INSTEAD OF daily.sh in the cron entry (LibreNMS's is /etc/cron.d/librenms):
#
#   15 0 * * *  librenms  /path/to/librenms-theme-selector/scripts/daily-wrapper.sh >> /dev/null 2>&1
#
# A `./daily.sh` run by hand bypasses it; run the wrapper by hand instead.
#
# Environment: LIBRENMS (default /opt/librenms), DAILY_SH (default $LIBRENMS/daily.sh).
set -u

LIBRENMS="${LIBRENMS:-/opt/librenms}"
HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PATCHER="$HERE/patch-core.sh"
DAILY="${DAILY_SH:-$LIBRENMS/daily.sh}"

[ -x "$DAILY" ] || { echo "daily-wrapper: cannot run $DAILY" >&2; exit 1; }
[ -f "$PATCHER" ] || { echo "daily-wrapper: missing $PATCHER" >&2; exit 1; }

# Was the patch (or its old two-file form) in place?
status="$(sh "$PATCHER" status --librenms "$LIBRENMS" 2>/dev/null || true)"
reapply=0
if printf '%s\n' "$status" | grep -q '^APPLIED' || printf '%s\n' "$status" | grep -q 'old port-key declaration'; then
  reapply=1
fi

restore() {
  if [ "$reapply" = 1 ]; then
    if ! sh "$PATCHER" apply --librenms "$LIBRENMS" >/dev/null 2>&1; then
      echo "daily-wrapper: the port-graph patch could not be re-applied (LibreNMS changed generic_data.inc.php?)." >&2
      echo "daily-wrapper: the update itself ran. Port graphs use stock colours until: $PATCHER apply" >&2
    fi
  fi
}
trap restore EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

if [ "$reapply" = 1 ]; then
  if ! sh "$PATCHER" revert --librenms "$LIBRENMS" >/dev/null 2>&1; then
    echo "daily-wrapper: could not reverse the patch; daily.sh's git pull may stop on it." >&2
  fi
fi

"$DAILY" "$@"
rc=$?
exit "$rc"
