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
# includes/html/graphs/generic_data.inc.php hard-codes the six colours of the
# port traffic series (#90B040 green in, #8080C0 lavender out) and reads no
# config at all. port_bits renders through it, so port graphs stay stock
# green-and-lavender under every skin. The patch introduces
# graph_colours.port_in / .port_out, defaulting to exactly the values it
# replaces - so with no config set, output is byte-identical.
#
# THE CATCH
# daily.sh updates LibreNMS with `git pull` / `git checkout`, which reverts
# tracked files. Re-run `patch-core.sh apply` after every update. The script
# is idempotent and safe to run from cron or a post-update hook.
#
#   ./scripts/patch-core.sh status
#   ./scripts/patch-core.sh apply
#   ./scripts/patch-core.sh revert
#
# Needs write access to $LIBRENMS - run as root or the librenms user.
set -eu

LIBRENMS="/opt/librenms"
REPO="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PATCHFILE="$REPO/patches/0001-generic_data-read-port-series-colours-from-config.patch"
# Two files: the helper itself, and the config definitions - lnms config:set
# refuses any key not declared there, so the keys have to be registered.
TARGET="includes/html/graphs/generic_data.inc.php"
TARGETS="includes/html/graphs/generic_data.inc.php resources/definitions/config_definitions.json"
MARKER="graph_colours.port_in"
DRY=0

usage() {
  sed -n '2,29p' "$0" | sed 's/^# \{0,1\}//'
  exit "${1:-0}"
}

ACTION=""
while [ $# -gt 0 ]; do
  case "$1" in
    apply|revert|status) ACTION="$1"; shift ;;
    --librenms) LIBRENMS="${2:-}"; shift 2 ;;
    --dry-run)  DRY=1; shift ;;
    -h|--help)  usage 0 ;;
    *) echo "unknown argument: $1" >&2; usage 1 ;;
  esac
done
[ -n "$ACTION" ] || usage 1

[ -d "$LIBRENMS" ] || { echo "not a directory: $LIBRENMS" >&2; exit 1; }
for t in $TARGETS; do
  [ -f "$LIBRENMS/$t" ] || { echo "missing: $LIBRENMS/$t" >&2; exit 1; }
done
[ -f "$PATCHFILE" ] || { echo "missing patch: $PATCHFILE" >&2; exit 1; }
command -v patch >/dev/null 2>&1 || { echo "the 'patch' command is required" >&2; exit 1; }

# Applied only when BOTH files carry the marker - a half-applied state (the
# helper patched but the key undeclared) silently fails at config:set time.
applied() {
  for t in $TARGETS; do
    grep -q "$MARKER" "$LIBRENMS/$t" || return 1
  done
  return 0
}

run() {
  if [ "$DRY" -eq 1 ]; then echo "  would run: $*"; else eval "$@"; fi
}

case "$ACTION" in

status)
  if applied; then
    echo "APPLIED    $LIBRENMS/$TARGET"
    echo
    echo "Port graphs read graph_colours.port_in / .port_out."
    echo "Re-run 'apply' after any LibreNMS update - daily.sh reverts core files."
  else
    echo "NOT APPLIED    $LIBRENMS/$TARGET"
    echo
    echo "Port graphs use the hard-coded #90B040 / #8080C0 and ignore the skin."
  fi
  # Report on git's view too: if core is dirty for OTHER reasons, say so.
  if [ -d "$LIBRENMS/.git" ]; then
    dirty="$(git -C "$LIBRENMS" status --porcelain -- $TARGETS 2>/dev/null || true)"
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
  if applied; then
    echo "Already applied - nothing to do."
    exit 0
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
  for t in $TARGETS; do
    run "cp -p '$LIBRENMS/$t' '$LIBRENMS/$t.pre-skins-patch'"
  done
  run "patch -p1 -d '$LIBRENMS' --forward --no-backup-if-mismatch < '$PATCHFILE'"
  if [ "$DRY" -eq 0 ]; then
    if applied; then
      echo
      echo "  OK   patch applied"
      for t in $TARGETS; do echo "  OK   original saved as $t.pre-skins-patch"; done
      echo
      echo "Now re-save the instance default in Plugins -> Theme Selector, which"
      echo "writes these now that they exist. Or by hand (Protoss's colours):"
      echo "  lnms config:set -- graph_colours.port_in  '[\"9CF7DC\",\"3AD6A8\",\"218C6E\"]'"
      echo "  lnms config:set -- graph_colours.port_out '[\"FFE7A8\",\"E3B341\",\"95741F\"]'"
      echo
      echo "RE-RUN THIS AFTER EVERY LibreNMS UPDATE - daily.sh reverts core files."
    else
      echo "  FAIL patch reported success but the marker is absent" >&2
      exit 1
    fi
  fi
  ;;

revert)
  if ! applied; then
    echo "Not applied - nothing to do."
    exit 0
  fi
  # Prefer the pristine copy; fall back to reversing the diff.
  have_all=1
  for t in $TARGETS; do
    [ -f "$LIBRENMS/$t.pre-skins-patch" ] || have_all=0
  done
  if [ "$have_all" -eq 1 ]; then
    for t in $TARGETS; do
      run "cp -p '$LIBRENMS/$t.pre-skins-patch' '$LIBRENMS/$t'"
      run "rm -f '$LIBRENMS/$t.pre-skins-patch'"
      [ "$DRY" -eq 0 ] && echo "  OK   restored $t"
    done
  else
    run "patch -p1 -d '$LIBRENMS' --reverse --no-backup-if-mismatch < '$PATCHFILE'"
    [ "$DRY" -eq 0 ] && echo "  OK   patch reversed"
  fi
  if [ "$DRY" -eq 0 ]; then
    if applied; then
      echo "  FAIL marker still present after revert" >&2
      exit 1
    fi
    echo "  OK   core file is back to stock"
    echo
    echo "graph_colours.port_in / .port_out are now unread. Clear them if you like:"
    echo "  lnms config:clear graph_colours.port_in"
    echo "  lnms config:clear graph_colours.port_out"
  fi
  ;;

esac
