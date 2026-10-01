#!/bin/sh
# Apply or revert the optional core patch that lets port graphs read their
# series colours from config.
#
# WHY THIS IS SEPARATE FROM THE PLUGIN
# Everything else this repo does is confined to html/css/custom/ and a couple
# of config rows - no core file is touched, and LibreNMS updates leave it all
# alone. This does touch core, which is a different risk class, so it is
# opt-in, reversible, and never run automatically.
#
# WHAT IT CHANGES
# One file: includes/html/graphs/generic_data.inc.php. It hard-codes the six
# colours of the port traffic series (#90B040 green in, #8080C0 lavender out)
# and reads no config at all. port_bits renders through it, so port graphs stay
# stock green-and-lavender under every skin. The patch introduces
# graph_colours.port_in / .port_out, defaulting to exactly the values it
# replaces - so with no config set, output is byte-identical.
#
# (Earlier versions also patched resources/definitions/config_definitions.json
# to declare the two keys. That file changes upstream often, and the plugin does
# not need the declaration: `lnms config:set` refuses undeclared keys, but the
# plugin stores them with LibrenmsConfig::persist() and LibreNMS reads them back
# from the config table. `apply` removes the old declaration if it finds it.)
#
# THE CATCH - AND WHY `apply` ASKS YOU TO CONFIRM
# daily.sh updates with `git pull`. It does NOT quietly restore a tracked file
# that has local changes: if upstream also changed the patched file, the pull
# stops with "Your local changes ... would be overwritten by merge", and
# LibreNMS then stops updating (security fixes included) until someone notices.
# So the patch must never be in place when daily.sh runs. scripts/daily-wrapper.sh
# does that (reverse the patch, run daily.sh, re-apply from an EXIT trap), but it
# can only help if IT starts daily.sh: a cron line you wrote, or your own timer.
# If LibreNMS's own scheduler (librenms-scheduler.timer, `schedule:run`) starts
# daily.sh there is nowhere to put it: that call lives in a tracked file of the
# checkout, and editing that file would cause the same stopped pull.
#
# So `apply` refuses unless you pass --wrapped, meaning "daily.sh on this host is
# started through scripts/daily-wrapper.sh". Without the patch nothing is lost
# but the recoloured port graphs; everything else themes.
#
#   ./scripts/patch-core.sh status
#   ./scripts/patch-core.sh apply --wrapped
#   ./scripts/patch-core.sh revert
#
# `revert` reverses the patch itself (never restores a saved copy, which would
# be stale after an update), and falls back to `git checkout` only if the patch
# no longer reverses cleanly.
#
# Run it as the librenms user. (Run as root it still puts the file's owner back, because
# LibreNMS's validate page FAILs on any file under $LIBRENMS not owned by librenms and
# warns that it will stop updates.)
set -eu

LIBRENMS="/opt/librenms"
REPO="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PATCHFILE="$REPO/patches/0001-generic_data-read-port-series-colours-from-config.patch"
LEGACY_PATCHFILE="$REPO/patches/legacy-0001-config_definitions-declare-port-keys.patch"
TARGET="includes/html/graphs/generic_data.inc.php"
LEGACY_TARGET="resources/definitions/config_definitions.json"
MARKER="graph_colours.port_in"
DRY=0
WRAPPED=0

usage() {
  sed -n '2,50p' "$0" | sed 's/^# \{0,1\}//'
  exit "${1:-0}"
}

ACTION=""
while [ $# -gt 0 ]; do
  case "$1" in
    apply|revert|status) ACTION="$1"; shift ;;
    --librenms) LIBRENMS="${2:-}"; shift 2 ;;
    --dry-run)  DRY=1; shift ;;
    --wrapped)  WRAPPED=1; shift ;;
    -h|--help)  usage 0 ;;
    *) echo "unknown argument: $1" >&2; usage 1 ;;
  esac
done
[ -n "$ACTION" ] || usage 1

[ -d "$LIBRENMS" ] || { echo "not a directory: $LIBRENMS" >&2; exit 1; }
[ -f "$LIBRENMS/$TARGET" ] || { echo "missing: $LIBRENMS/$TARGET" >&2; exit 1; }
[ -f "$PATCHFILE" ] || { echo "missing patch: $PATCHFILE" >&2; exit 1; }
command -v patch >/dev/null 2>&1 || { echo "the 'patch' command is required" >&2; exit 1; }

applied() { grep -q "$MARKER" "$LIBRENMS/$TARGET"; }
# Who owns the files now, so that running as root does not leave them root-owned.
owner_of() { stat -c '%u:%g' "$LIBRENMS/$1" 2>/dev/null || true; }
OWN_TARGET="$(owner_of "$TARGET")"
OWN_LEGACY="$(owner_of "$LEGACY_TARGET")"
keep_owner() {
  [ "$DRY" -eq 0 ] && [ "$(id -u)" = 0 ] || return 0
  [ -n "$OWN_TARGET" ] && chown "$OWN_TARGET" "$LIBRENMS/$TARGET" 2>/dev/null
  [ -n "$OWN_LEGACY" ] && [ -f "$LIBRENMS/$LEGACY_TARGET" ] && chown "$OWN_LEGACY" "$LIBRENMS/$LEGACY_TARGET" 2>/dev/null
  return 0
}
# The old two-file patch also declared the keys in config_definitions.json.
legacy() { [ -f "$LIBRENMS/$LEGACY_TARGET" ] && grep -q "$MARKER" "$LIBRENMS/$LEGACY_TARGET"; }
in_git() { [ -e "$LIBRENMS/.git" ] && command -v git >/dev/null 2>&1 && git -C "$LIBRENMS" ls-files --error-unmatch -- "$TARGET" >/dev/null 2>&1; }

# Is LibreNMS's own scheduler (a systemd timer) set up on this host?
scheduler_timer() {
  command -v systemctl >/dev/null 2>&1 || return 1
  systemctl list-units --all --type=timer 2>/dev/null | grep -q 'librenms-scheduler'
}

run() {
  if [ "$DRY" -eq 1 ]; then echo "  would run: $*"; else eval "$@"; fi
}

# The backups earlier versions saved are stale after any update and must never be
# restored; remove them.
drop_old_backups() {
  for t in "$TARGET" "$LEGACY_TARGET"; do
    [ -f "$LIBRENMS/$t.pre-skins-patch" ] && run "rm -f '$LIBRENMS/$t.pre-skins-patch'"
  done
  return 0
}

# Undo the old config_definitions.json declaration, if a previous version left it.
remove_legacy() {
  legacy || return 0
  if [ -f "$LEGACY_PATCHFILE" ] && patch -p1 -d "$LIBRENMS" --reverse --dry-run < "$LEGACY_PATCHFILE" >/dev/null 2>&1; then
    run "patch -p1 -d '$LIBRENMS' --reverse --no-backup-if-mismatch < '$LEGACY_PATCHFILE'"
    keep_owner
    [ "$DRY" -eq 0 ] && echo "  OK   removed the old declaration from $LEGACY_TARGET"
  else
    cat >&2 <<EOF
  !!   $LEGACY_TARGET still carries the old port-key declaration and it does not
       reverse cleanly. It is what makes a LibreNMS update stop. Restore the file:
         git -C "$LIBRENMS" checkout -- $LEGACY_TARGET
EOF
    return 1
  fi
}

case "$ACTION" in

status)
  if applied; then
    echo "APPLIED    $LIBRENMS/$TARGET"
    echo
    echo "Port graphs read graph_colours.port_in / .port_out."
    echo "daily.sh must be started through scripts/daily-wrapper.sh: with this file patched,"
    echo "a git pull that finds upstream changes to it stops with an error."
    if scheduler_timer; then
      echo
      echo "WARNING    this host has LibreNMS's own scheduler (librenms-scheduler.timer). If it"
      echo "           starts daily.sh, the wrapper is not in front of it: revert the patch."
    fi
  else
    echo "NOT APPLIED    $LIBRENMS/$TARGET"
    echo
    echo "Port graphs use the hard-coded #90B040 / #8080C0 and ignore the skin."
  fi
  if legacy; then
    echo
    echo "WARNING    $LIBRENMS/$LEGACY_TARGET still has the old port-key declaration"
    echo "           (from the earlier two-file patch). Run 'revert' or 'apply' to remove it:"
    echo "           it is what makes a daily.sh update stop when upstream edits that file."
  fi
  if [ -e "$LIBRENMS/.git" ] && command -v git >/dev/null 2>&1; then
    dirty="$(git -C "$LIBRENMS" status --porcelain -- "$TARGET" "$LEGACY_TARGET" 2>/dev/null || true)"
    echo
    if [ -n "$dirty" ]; then
      echo "git sees these core files as modified:"
      echo "$dirty" | sed 's/^/  /'
    else
      echo "git sees both files as clean (so they are stock, or the patch was committed)."
    fi
  fi
  ;;

apply)
  remove_legacy || exit 1
  drop_old_backups
  if applied; then
    echo "Already applied - nothing to do."
    exit 0
  fi
  if [ "$WRAPPED" -ne 1 ]; then
    cat >&2 <<EOF
Not applied. This patch edits a tracked LibreNMS file, and a patched file can stop
daily.sh's git pull ("local changes would be overwritten"), which stops LibreNMS
updating, security fixes included.

Apply it only if daily.sh on this host is started through scripts/daily-wrapper.sh
(a cron line you control, or your own timer), then say so:

  $0 apply --wrapped

EOF
    if scheduler_timer; then
      cat >&2 <<EOF
This host has LibreNMS's own scheduler (librenms-scheduler.timer). If that is what runs
daily.sh, there is nowhere to put the wrapper, and the safe choice is to leave the patch
off: everything else themes, only the port graphs keep their stock colours.

EOF
    fi
    exit 1
  fi
  # Dry-run first so a version drift fails loudly instead of leaving .rej files
  # scattered through core.
  if ! patch -p1 -d "$LIBRENMS" --forward --dry-run < "$PATCHFILE" >/dev/null 2>&1; then
    cat >&2 <<EOF
The patch does not apply cleanly to $LIBRENMS/$TARGET.

That usually means LibreNMS has changed this file upstream. Nothing has been
modified. Check the real diff and regenerate the patch against the current
file before trying again:

  patch -p1 -d "$LIBRENMS" --forward --dry-run < "$PATCHFILE"
EOF
    exit 1
  fi
  run "patch -p1 -d '$LIBRENMS' --forward --no-backup-if-mismatch < '$PATCHFILE'"
  keep_owner
  if [ "$DRY" -eq 0 ]; then
    if applied; then
      echo
      echo "  OK   patch applied to $TARGET"
      echo
      echo "Now re-save the instance default in Plugins -> Theme Selector, which"
      echo "writes the two port keys. Or by hand, with the plugin's own persist (lnms"
      echo "config:set refuses keys LibreNMS does not declare):"
      echo "  php artisan tinker --execute='App\\Facades\\LibrenmsConfig::persist(\"graph_colours.port_in\", [\"9CF7DC\",\"3AD6A8\",\"218C6E\"]);'"
      echo
      echo "daily.sh MUST now be started through scripts/daily-wrapper.sh, or the first update"
      echo "that touches this file will stop with 'local changes would be overwritten'."
    else
      echo "  FAIL patch reported success but the marker is absent" >&2
      exit 1
    fi
  fi
  ;;

revert)
  remove_legacy || exit 1
  drop_old_backups
  if ! applied; then
    echo "Not applied - nothing to do."
    exit 0
  fi
  # Reverse the patch itself; never copy a saved file back (it is stale after an update).
  if patch -p1 -d "$LIBRENMS" --reverse --dry-run < "$PATCHFILE" >/dev/null 2>&1; then
    run "patch -p1 -d '$LIBRENMS' --reverse --no-backup-if-mismatch < '$PATCHFILE'"
    keep_owner
    [ "$DRY" -eq 0 ] && echo "  OK   patch reversed"
  elif in_git; then
    echo "  !!   the patch no longer reverses cleanly; restoring the file from git instead" >&2
    echo "       (any other local change to $TARGET is lost)" >&2
    run "git -C '$LIBRENMS' checkout -- '$TARGET'"
    keep_owner
  else
    echo "  FAIL the patch does not reverse cleanly and $LIBRENMS is not a git checkout" >&2
    exit 1
  fi
  if [ "$DRY" -eq 0 ]; then
    if applied; then
      echo "  FAIL marker still present after revert" >&2
      exit 1
    fi
    echo "  OK   core file is back to stock"
    echo
    echo "graph_colours.port_in / .port_out are now unread. Clear them if you like:"
    echo "  php artisan tinker --execute='App\\Facades\\LibrenmsConfig::erase(\"graph_colours.port_in\"); App\\Facades\\LibrenmsConfig::erase(\"graph_colours.port_out\");'"
  fi
  ;;

esac
