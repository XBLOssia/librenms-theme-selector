#!/bin/sh
# Render authentic RRDtool port-traffic graphs from SYNTHETIC data, one set per
# skin, for use in the offline mockup and the README screenshots.
#
# WHY NOT JUST SCREENSHOT PRODUCTION
# A real dashboard carries real hostnames, real interface descriptions, real
# locations and a map centred on real geography. Redacting all of that after
# the fact is error-prone and tends to look it. Generating the graphs from
# invented data means there is nothing to redact: no production value ever
# enters the image.
#
# WHY NOT FAKE THE GRAPHS IN CSS
# Because then the screenshots would not be showing the thing being claimed.
# These are rendered by the same rrdtool binary, with the same chrome options
# LibreNMS passes (rrdgraph_def_text_dark) and the same three-tone in/out ramps
# the skin sets (graph_colours.port_in / .port_out) - so the output is what the
# skin genuinely produces, just from traffic that never existed.
#
# Needs rrdtool. Run it anywhere that has one; it touches nothing but its own
# temp directory and the output directory.
#
#   ./scripts/make-demo-graphs.sh [outdir]      # default: harness/graphs
set -eu

REPO="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
OUT="${1:-$REPO/harness/graphs}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

command -v rrdtool >/dev/null 2>&1 || { echo "rrdtool is required" >&2; exit 1; }
mkdir -p "$OUT"

STEP=300
POINTS=288                      # 24h at 5-minute resolution
END=1750000000                  # fixed epoch => byte-reproducible output
START=$((END - STEP * POINTS))
RRD="$TMP/demo.rrd"

# --- synthetic traffic -------------------------------------------------------
# A plausible campus uplink: diurnal curve, downstream-heavy, a lunchtime dip,
# one evening backup spike. Deterministic - no RNG - so re-running produces
# identical PNGs and the images do not churn in git.
rrdtool create "$RRD" --start "$((START - STEP))" --step "$STEP" \
  DS:INOCTETS:GAUGE:600:0:U \
  DS:OUTOCTETS:GAUGE:600:0:U \
  RRA:AVERAGE:0.5:1:"$POINTS" \
  RRA:MAX:0.5:1:"$POINTS"

i=0
while [ "$i" -lt "$POINTS" ]; do
  t=$((START + i * STEP))
  # awk does the shaping: hour-of-day sine, a dip at 12:00, a spike at 21:00.
  vals="$(awk -v i="$i" -v n="$POINTS" 'BEGIN{
    h = (i / n) * 24;
    base = 0.5 + 0.5 * sin((h - 8) / 24 * 6.2831853);
    if (base < 0.06) base = 0.06;
    dip   = (h > 11.5 && h < 13.0) ? 0.55 : 1.0;
    spike = (h > 20.5 && h < 22.0) ? 2.4  : 1.0;
    wobble = 1 + 0.10 * sin(i * 1.7) + 0.05 * sin(i * 0.31);
    down = 34000000 * base * dip * spike * wobble;      # bytes/sec in
    up   = down * (0.22 + 0.05 * sin(i * 0.9));         # bytes/sec out
    printf "%d:%d", down, up;
  }')"
  printf '%s %s\n' "$t" "$vals" >> "$TMP/feed.txt"
  i=$((i + 1))
done
while read -r t v; do rrdtool update "$RRD" "$t:$v"; done < "$TMP/feed.txt"

# --- per-skin rendering ------------------------------------------------------
# Pull the exact values the skin ships, so the demo cannot drift from the real
# thing: chrome from graph.conf's rrdgraph_def_text_dark, series from port_in
# and port_out.
conf_val() { grep "^$1=" "$2" | head -1 | cut -d= -f2-; }
ramp() {   # ramp <json-array> <index>  ->  bare hex
  printf '%s' "$1" | tr -d '[]" ' | cut -d, -f"$2"
}

render() {  # render <skin> <width> <height> <suffix>
  skin="$1"; w="$2"; h="$3"; sfx="$4"
  gc="$REPO/skins/$skin/graph.conf"
  # A skin's palette is for the mode it is written for: the _dark pair for a dark skin, the plain pair for a light one.
  chrome="$(conf_val rrdgraph_def_text_dark "$gc")"
  fontc="$(conf_val rrdgraph_def_text_color_dark "$gc")"
  [ -n "$chrome" ] || chrome="$(conf_val rrdgraph_def_text "$gc")"
  [ -n "$fontc" ] || fontc="$(conf_val rrdgraph_def_text_color "$gc")"
  pin="$(conf_val graph_colours.port_in "$gc")"
  pout="$(conf_val graph_colours.port_out "$gc")"

  # shellcheck disable=SC2086 -- $chrome is a deliberate option string
  rrdtool graph "$OUT/$skin-port_bits$sfx.png" \
    --start "$START" --end "$END" \
    --width "$w" --height "$h" \
    --imgformat PNG --font "LEGEND:7:" --font "AXIS:7:" \
    --font-render-mode normal \
    --color "FONT#$fontc" \
    $chrome \
    --vertical-label "bits/sec" \
    --alt-autoscale \
    --units-exponent 9 \
    "DEF:inoct=$RRD:INOCTETS:AVERAGE" \
    "DEF:outoct=$RRD:OUTOCTETS:AVERAGE" \
    "DEF:inoct_max=$RRD:INOCTETS:MAX" \
    "DEF:outoct_max=$RRD:OUTOCTETS:MAX" \
    'CDEF:inbits=inoct,8,*' \
    'CDEF:outbits=outoct,8,*' \
    'CDEF:inbits_max=inoct_max,8,*' \
    'CDEF:doutbits=outoct,8,*,-1,*' \
    'CDEF:doutbits_max=outoct_max,8,*,-1,*' \
    'COMMENT:bps      Now       Ave       Max\n' \
    "AREA:inbits_max#$(ramp "$pin" 1)" \
    "AREA:inbits#$(ramp "$pin" 2)" \
    "LINE1:inbits#$(ramp "$pin" 3):In " \
    'GPRINT:inbits:LAST:%6.2lf%s' \
    'GPRINT:inbits:AVERAGE:%6.2lf%s' \
    'GPRINT:inbits_max:MAX:%6.2lf%s\n' \
    "AREA:doutbits_max#$(ramp "$pout" 1)" \
    "AREA:doutbits#$(ramp "$pout" 2)" \
    "LINE1:doutbits#$(ramp "$pout" 3):Out" \
    'GPRINT:outbits:LAST:%6.2lf%s' \
    'GPRINT:outbits:AVERAGE:%6.2lf%s' \
    'GPRINT:outbits:MAX:%6.2lf%s\n' \
    >/dev/null
  echo "  $skin-port_bits$sfx.png"
}

echo "Rendering demo graphs into $OUT"
for skin in terran protoss zerg digital-rain clock-tower-daylight clock-tower-lantern; do
  [ -f "$REPO/skins/$skin/graph.conf" ] || continue
  render "$skin" 480 130 ""
  render "$skin" 860 180 "-wide"
done

echo
echo "Done. These contain no production data - the traffic is generated by an"
echo "awk expression in this script and the epoch is fixed, so re-running"
echo "produces identical files."
