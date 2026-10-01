#!/bin/sh
# Does the one-helper change (dev/port-colours-diff.py) leave port graphs byte-identical
# with default config, and does it make them follow graph_colours.port_in/out?
#
#   sh dev/test-port-colours.sh        # run on the WSL/Linux host, with the dev stack up
#
# It copies generic_data.inc.php and config_definitions.json out of the dev
# container, applies the change to the copies, swaps them into the container,
# draws the same graph over a fixed window before and after, then restores the
# originals (also on failure or Ctrl-C) and removes the config rows it set.
# Needs the graph fixture: run `docker exec theme-selector-dev-librenms-1 sh /plugin/dev/test-graphs.sh`
# once first (it is idempotent).
set -u
HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
C=theme-selector-dev-librenms-1
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
GD=includes/html/graphs/generic_data.inc.php
CD=resources/definitions/config_definitions.json

mkdir -p "$T/in" "$T/out"
for f in "$GD" "$CD"; do
  mkdir -p "$T/in/$(dirname "$f")"
  docker cp "$C:/opt/librenms/$f" "$T/in/$f" || exit 1
done
python3 "$HERE/port-colours-diff.py" "$T/in" --out "$T/out" >/dev/null || exit 1
docker exec "$C" mkdir -p /tmp/new
docker cp "$T/out/$GD" "$C:/tmp/new/generic_data.inc.php" || exit 1
docker cp "$T/out/$CD" "$C:/tmp/new/config_definitions.json" || exit 1

docker exec -i "$C" sh -s <<'INNER'
set -u
cd /opt/librenms
B=http://127.0.0.1:8000
DB="mariadb -h db -u librenms -plibrenms-dev librenms -N -e"
GD=includes/html/graphs/generic_data.inc.php
CD=resources/definitions/config_definitions.json
cp $GD /tmp/orig.gd; cp $CD /tmp/orig.cd
FAILED=0
restore() {
  cp /tmp/orig.gd $GD; cp /tmp/orig.cd $CD
  gosu librenms php artisan config:clear >/dev/null 2>&1
  gosu librenms php artisan tinker --execute='App\Facades\LibrenmsConfig::erase("graph_colours.port_in"); App\Facades\LibrenmsConfig::erase("graph_colours.port_out");' >/dev/null 2>&1
  cmp -s $GD /tmp/orig.gd && cmp -s $CD /tmp/orig.cd && echo "  (stock files restored)" || echo "  (RESTORE FAILED: copy /tmp/orig.* back by hand)"
}
trap restore EXIT
check() { if [ "$2" = ok ]; then echo "  ok    $1"; else echo "  FAIL  $1"; FAILED=1; fi; }

HOST=graphtest.example.net
DID=$($DB "select device_id from devices where hostname='$HOST'")
[ -n "$DID" ] || { echo "no graph fixture: run dev/test-graphs.sh once first"; exit 1; }
PID=$($DB "select port_id from ports where device_id=$DID")
LAST=$(rrdtool last /data/rrd/$HOST/port-id$PID.rrd); FROM=$((LAST - 86400))
jar=/tmp/jar-pc
rm -f $jar; curl -s -o /dev/null -c $jar -b $jar -H "X-Dev-User: dev-admin" $B/devices
tok=$(curl -s -c $jar -b $jar -H "X-Dev-User: dev-admin" $B/plugin/theme-selector | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -c $jar -b $jar -H "X-Dev-User: dev-admin" --data "_token=$tok&skin=none" $B/plugin/theme-selector
graph() { curl -s -b $jar -H "X-Dev-User: dev-admin" "$B/graph.php?type=port_bits&id=$PID&from=$FROM&to=$LAST&width=$1&height=$2" | sha256sum | cut -c1-16; }

echo "== before: stock files"
check "the helper still has its hex literals" "$([ "$(grep -c 'graph_colours.port_in' $GD)" = 0 ] && echo ok || echo no)"
A1=$(graph 300 120); A2=$(graph 700 250)
echo "  port_bits 300x120=$A1  700x250=$A2"

echo "== after: helper reads config, defaults declared"
cp /tmp/new/generic_data.inc.php $GD; cp /tmp/new/config_definitions.json $CD
gosu librenms php artisan config:clear >/dev/null 2>&1
B1=$(graph 300 120); B2=$(graph 700 250)
echo "  port_bits 300x120=$B1  700x250=$B2"
check "the helper reads graph_colours.port_in/out" "$([ "$(grep -c 'graph_colours.port_' $GD)" = 6 ] && echo ok || echo no)"
check "default config: 300x120 is byte-identical" "$([ "$A1" = "$B1" ] && echo ok || echo no)"
check "default config: 700x250 is byte-identical" "$([ "$A2" = "$B2" ] && echo ok || echo no)"
D=$(gosu librenms php artisan tinker --execute='echo json_encode([App\Facades\LibrenmsConfig::get("graph_colours.port_in"), App\Facades\LibrenmsConfig::get("graph_colours.port_out")]);' 2>/dev/null | tail -1)
check "the declared defaults are the old literals" "$([ "$D" = '[["D7FFC7","90B040","608720"],["E0E0FF","8080C0","606090"]]' ] && echo ok || echo no)"

echo "== after: a configured palette is used"
gosu librenms php artisan tinker --execute='App\Facades\LibrenmsConfig::persist("graph_colours.port_in", ["FF0000","00FF00","0000FF"]);' >/dev/null 2>&1
C1=$(graph 300 120)
check "port_in set: the graph changes" "$([ "$C1" != "$A1" ] && echo ok || echo no)"
gosu librenms php artisan tinker --execute='App\Facades\LibrenmsConfig::persist("graph_colours.port_in", ["D7FFC7","90B040","608720"]);' >/dev/null 2>&1
C2=$(graph 300 120)
check "port_in set back to the defaults: byte-identical again" "$([ "$C2" = "$A1" ] && echo ok || echo no)"

[ "$FAILED" = 0 ] && echo "all checks passed" || { echo "SOME CHECKS FAILED"; exit 1; }
INNER
