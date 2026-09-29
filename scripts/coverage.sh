#!/usr/bin/env bash
# Report which colour-bearing components base/base.css does not style.
#
#   ./scripts/coverage.sh /opt/librenms
#   ./scripts/coverage.sh /opt/librenms -v       # list the misses
#
# All skins share base.css, so this is one number, not one per skin. It counts
# selectors named, not properties answered: harness/leaks.html is the check
# for whether a stock rule still wins.
#
# TWO DENOMINATORS, because the first one alone hid a real gap for a while:
#
#   tw_dark.css   components upstream gives dark-mode treatment. Ignoring one
#                 means stock dark colours sitting inside the skin.
#   styles.css    colour-bearing classes that tw_dark.css NEVER overrides, so
#                 they render identically in light and dark. Measuring only
#                 against tw_dark.css reported 100% while ~112 of these were
#                 untouched — including the navbar search dropdown (#fff), the
#                 availability map boxes, and several pale pastel row
#                 backgrounds that only make sense on a light background.
#
# Exit status is always 0 - this is a report, not a gate.

set -uo pipefail

SRC="${1:-/opt/librenms}"
ONLY="${2:-}"
VERBOSE="${3:-}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TW="$SRC/html/css/tw_dark.css"
ST="$SRC/html/css/styles.css"

case "${ONLY}" in -v|--verbose) VERBOSE="-v"; ONLY="" ;; esac

if [ ! -f "$TW" ] || [ ! -f "$ST" ]; then
  echo "error: need $TW and $ST - pass the path to a LibreNMS checkout" >&2
  echo "usage: $0 /path/to/librenms [-v]" >&2
  exit 1
fi

skins="base"

# Group A: components tw_dark.css themes.
group_a=$(grep -ohE '\.dark [.#][a-zA-Z0-9_-]+' "$TW" | sed 's/^\.dark //' | awk '!s[$0]++')

# Group B: colour-bearing classes in styles.css that tw_dark.css never touches.
# The pipe must sit on the command line BEFORE the heredoc body, not after the
# terminator. python3 on Windows emits CRLF, and a trailing \r makes every
# later `grep -F` miss silently - which is exactly how this whole group went
# unnoticed in the first place.
group_b=$(python3 - "$ST" "$TW" <<'PYEOF' | tr -d '\r'
import re, sys
st = open(sys.argv[1], encoding='utf-8', errors='replace').read()
tw = open(sys.argv[2], encoding='utf-8', errors='replace').read()
cls = set()
for m in re.finditer(r'(^|\})\s*([^{}@]+)\{([^}]*)\}', st, re.M):
    if re.search(r'\b(background-color|background|color|border-color)\s*:\s*(#|rgba?\()', m.group(3)):
        cls.update('.' + c for c in re.findall(r'\.([a-zA-Z][\w-]+)', m.group(2)))
tw_cls = {'.' + c for c in re.findall(r'\.dark [^{]*?\.([a-zA-Z][\w-]+)', tw)}
print('\n'.join(sorted(cls - tw_cls)))
PYEOF
)


# $1 is a selector token like ".panel-heading" or "#overDiv". Substring
# matching over-counted - a skin containing `.rules-group-header .active`
# made `.active` look covered - so require the name to end at a non-name
# character. Handles both class and id prefixes.
match_class() {
  local tok="$1" f="$2" pre name
  pre="${tok:0:1}"; name="${tok:1}"
  grep -qE -- "[${pre}]${name}([^a-zA-Z0-9_-]|$)" "$f"
}

count() { printf '%s\n' "$1" | grep -c . ; }
a_total=$(count "$group_a")
b_total=$(count "$group_b")

echo "Denominators"
printf '  A  tw_dark.css themed components            %4d\n' "$a_total"
printf '  B  styles.css colour classes tw_dark skips  %4d\n' "$b_total"
printf '     total                                    %4d\n' "$((a_total + b_total))"
echo

for skin in $skins; do
  css="$ROOT/base/base.css"
  if [ ! -f "$css" ]; then
    echo "$skin: no stylesheet at $css - skipping"; continue
  fi

  miss_a=""; miss_b=""; na=0; nb=0
  while read -r c; do
    [ -z "$c" ] && continue
    match_class "$c" "$css" || { miss_a="$miss_a $c"; na=$((na+1)); }
  done <<< "$group_a"
  while read -r c; do
    [ -z "$c" ] && continue
    match_class "$c" "$css" || { miss_b="$miss_b $c"; nb=$((nb+1)); }
  done <<< "$group_b"

  cov_a=$((a_total - na)); cov_b=$((b_total - nb))
  tot=$((a_total + b_total)); cov=$((cov_a + cov_b))
  printf '%-9s %3d/%-3d (%3d%%)   A %3d/%-3d   B %3d/%-3d\n' \
    "$skin" "$cov" "$tot" "$((cov * 100 / tot))" "$cov_a" "$a_total" "$cov_b" "$b_total"

  if [ "$VERBOSE" = "-v" ]; then
    [ -n "$miss_a" ] && { echo "    missing (A):"; printf '%s\n' $miss_a | sed 's/^/      /'; }
    [ -n "$miss_b" ] && { echo "    missing (B):"; printf '%s\n' $miss_b | sed 's/^/      /'; }
    echo
  fi
done

echo
echo "Note: group B includes legacy classes that may no longer render anywhere."
echo "A miss there is worth checking against a real page before chasing it."
