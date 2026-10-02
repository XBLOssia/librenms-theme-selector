#!/usr/bin/env bash
# Capture a full-page screenshot of harness/mockup.html for each skin.
#
# Headless Chrome rather than a manual screengrab, for the same reason the
# graphs are generated rather than cropped: the output is deterministic. Same
# window size, same device scale factor, same fixed synthetic data, so the
# three images differ only by skin and re-running does not churn the repo.
#
# The mockup carries no production data - see harness/mockup.html.
#
#   ./scripts/capture-mockups.sh                 # -> docs/img/
#   ./scripts/capture-mockups.sh /tmp/shots      # somewhere else
#
# Needs the harness served first:
#   python -m http.server 8777
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/img}"
PORT="${PORT:-8777}"
BASE="http://localhost:$PORT/harness/mockup.html"
WIDTH=1500
HEIGHT=1110          # full page height at 1500px wide, measured 2026-10-02; no scrollbar, no empty strip

# --- find a Chromium-family browser -----------------------------------------
BROWSER=""
for c in google-chrome chromium chromium-browser chrome msedge; do
  if command -v "$c" >/dev/null 2>&1; then BROWSER="$(command -v "$c")"; break; fi
done
if [ -z "$BROWSER" ]; then
  for p in \
    "/c/Program Files/Google/Chrome/Application/chrome.exe" \
    "/c/Program Files (x86)/Google/Chrome/Application/chrome.exe" \
    "/c/Program Files/Microsoft/Edge/Application/msedge.exe" \
    "/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" \
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"; do
    if [ -f "$p" ]; then BROWSER="$p"; break; fi
  done
fi
if [ -z "$BROWSER" ]; then
  echo "error: no Chromium-family browser found (chrome / chromium / msedge)." >&2
  exit 1
fi

# --- is the harness actually being served? ----------------------------------
# Worth guarding: Chrome exits 0 after screenshotting a connection-refused
# page, and a small dark error page looks enough like a dark theme to slip
# past a glance. The reachability probe is advisory (curl may be absent or
# sandboxed); the real check is the size assertion after each capture, which
# needs no extra tooling.
MIN_BYTES=100000
if command -v curl >/dev/null 2>&1 && curl -fsS -o /dev/null "$BASE?skin=terran" 2>/dev/null; then
  echo "harness reachable on port $PORT"
else
  echo "note: could not probe port $PORT (curl absent or blocked) - relying on"
  echo "      the output size check instead. If captures fail, start the server:"
  echo "        python -m http.server $PORT"
fi

mkdir -p "$OUT"
echo "Browser: $BROWSER"
echo "Output:  $OUT"
echo

for skin in terran protoss zerg digital-rain clock-tower-daylight clock-tower-lantern; do
  dest="$OUT/dashboard-$skin.png"
  # Chrome on Windows needs a native path for --screenshot even under Git Bash.
  if command -v cygpath >/dev/null 2>&1; then
    native="$(cygpath -w "$dest")"
  else
    native="$dest"
  fi

  "$BROWSER" \
    --headless=new \
    --disable-gpu \
    --hide-scrollbars \
    --force-device-scale-factor=1 \
    --virtual-time-budget=5000 \
    --window-size="$WIDTH,$HEIGHT" \
    --screenshot="$native" \
    "$BASE?skin=$skin" >/dev/null 2>&1

  if [ ! -f "$dest" ]; then
    echo "  FAILED: $skin - no file written" >&2
    exit 1
  fi
  size="$(wc -c < "$dest")"
  if [ "$size" -lt "$MIN_BYTES" ]; then
    echo "  FAILED: $skin - only $size bytes." >&2
    echo "          A real capture is ~500KB. This is almost certainly a" >&2
    echo "          connection-refused page. Is the harness being served?" >&2
    exit 1
  fi
  echo "  dashboard-$skin.png  ($size bytes)"
done

echo
echo "Done. ${WIDTH}x${HEIGHT}, no production data."
