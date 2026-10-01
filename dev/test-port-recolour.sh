#!/bin/sh
# Do port traffic graphs follow a skin's port colours WITHOUT any edit to LibreNMS?
#
#   sh dev/test-port-recolour.sh        # on the WSL/Linux host, with the dev stack up
#
# RecolouringRrd (src/Graph) rewrites the six series options of generic_data.inc.php just before
# rrdtool draws, so a stock LibreNMS (no core patch) is enough. This draws port_bits as SVG for
# different skins and checks which colours are in the drawing, which also shows that stock output
# is untouched. Needs the graph fixture (run dev/test-graphs.sh once; it is idempotent) and
# python3 on the host. Leaves no default skin set and dev-admin following the default.
set -u
C="${TS_CONTAINER:-theme-selector-dev-librenms-1}"
D="docker exec -i $C"
FAILED=0

check() { if [ "$2" = ok ]; then echo "  ok    $1"; else echo "  FAIL  $1"; FAILED=1; fi; }

PIDS=$($D sh -s <<'EOF'
DB="mariadb -h db -u librenms -plibrenms-dev librenms -N -e"
DID=$($DB "select device_id from devices where hostname='graphtest.example.net'")
PID=$($DB "select port_id from ports where device_id=$DID")
LAST=$(rrdtool last /data/rrd/graphtest.example.net/port-id$PID.rrd)
echo "$DID $PID $LAST"
EOF
)
set -- $PIDS
DID=${1:-}; PID=${2:-}; LAST=${3:-}
[ -n "$PID" ] || { echo "no graph fixture: run dev/test-graphs.sh once first"; exit 1; }
FROM=$((LAST - 86400))

# one logged-in session per dev user, inside the container
$D sh -s <<'EOF'
B=http://127.0.0.1:8000
for u in dev-admin dev-user; do
  rm -f /tmp/jar-rc-$u
  curl -s -o /dev/null -c /tmp/jar-rc-$u -b /tmp/jar-rc-$u -H "X-Dev-User: $u" $B/devices
done
EOF

post() { # user path data
  $D sh -s <<EOF
B=http://127.0.0.1:8000
tok=\$(curl -s -c /tmp/jar-rc-$1 -b /tmp/jar-rc-$1 -H "X-Dev-User: $1" \$B/plugin/theme-selector | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -c /tmp/jar-rc-$1 -b /tmp/jar-rc-$1 -H "X-Dev-User: $1" --data "_token=\$tok&$3" \$B$2
EOF
}
choose() { post "$1" /plugin/theme-selector "skin=$2"; }
set_default() { post dev-admin /plugin/theme-selector/default "default=$1"; }
svg() { # user
  $D curl -s -b /tmp/jar-rc-$1 -H "X-Dev-User: $1" \
    "http://127.0.0.1:8000/graph.php?type=port_bits&id=$PID&from=$FROM&to=$LAST&width=500&height=200&graph_type=svg"
}
# colours present in an SVG that match a palette: prints how many of its six colours are present
has() { # file, six hex colours
  python3 - "$@" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8', errors='replace').read()
present = {'%02X%02X%02X' % tuple(round(float(v) * 2.55) for v in m) for m in re.findall(r'rgb\(([\d.]+)%, ([\d.]+)%, ([\d.]+)%\)', s) for m in [m]}
print(sum(1 for c in sys.argv[2:] if c.upper() in present))
PY
}

# Render port_bits in a legacy-style CLI process (includes/init.php, as alerts.php does), the way
# an alert email or chat message gets its graph: no session, so the instance default applies.
cli_svg() {
  $D sh -c 'cat > /tmp/rc-cli.php && chmod 644 /tmp/rc-cli.php' <<PHPEOF
<?php
\$init_modules = ['laravel'];
chdir('/opt/librenms');
require '/opt/librenms/includes/init.php';
echo LibreNMS\Util\Graph::getImageData(['type' => 'port_bits', 'id' => $PID, 'from' => $FROM, 'to' => $LAST, 'width' => 500, 'height' => 200, 'graph_type' => 'svg']);
PHPEOF
  $D gosu librenms php /tmp/rc-cli.php
}

STOCK="D7FFC7 90B040 608720 E0E0FF 8080C0 606090"
PROTOSS="9CF7DC 3AD6A8 218C6E FFE7A8 E3B341 95741F"
TERRAN="9CF2B4 46C96B 26823E FFD978 E0AA1E 926C12"
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT

echo "== the unmodified LibreNMS"
check "generic_data.inc.php still has its literals (no core patch)" "$($D grep -c 'graph_colours.port_in' /opt/librenms/includes/html/graphs/generic_data.inc.php | grep -qx 0 && echo ok || echo no)"
check "the store reports itself recolourable" "$($D sh -c "cd /opt/librenms && gosu librenms php artisan tinker --execute='echo Xblossia\\ThemeSelector\\Graph\\PortSeriesSupport::compatible() ? \"yes\" : \"no\";'" 2>/dev/null | tail -1 | grep -qx yes && echo ok || echo no)"

set_default ""
echo "== per-user skins"
choose dev-admin none;    svg dev-admin > "$T/none.svg"
choose dev-admin protoss; svg dev-admin > "$T/protoss.svg"
choose dev-admin terran;  svg dev-admin > "$T/terran.svg"
check "stock: all six stock colours drawn" "$([ "$(has "$T/none.svg" $STOCK)" = 6 ] && echo ok || echo no)"
check "stock: none of protoss's port colours" "$([ "$(has "$T/none.svg" $PROTOSS)" = 0 ] && echo ok || echo no)"
check "protoss: all six of its port colours drawn" "$([ "$(has "$T/protoss.svg" $PROTOSS)" = 6 ] && echo ok || echo no)"
check "protoss: none of the stock series colours left" "$([ "$(has "$T/protoss.svg" $STOCK)" = 0 ] && echo ok || echo no)"
check "terran: all six of its port colours drawn" "$([ "$(has "$T/terran.svg" $TERRAN)" = 6 ] && echo ok || echo no)"
check "terran: none of protoss's" "$([ "$(has "$T/terran.svg" $PROTOSS)" = 0 ] && echo ok || echo no)"

echo "== instance default"
set_default protoss
choose dev-admin none;  svg dev-admin > "$T/a.svg"
choose dev-user "";     svg dev-user  > "$T/u.svg"
check "a user who chose stock still gets stock under a protoss default" "$([ "$(has "$T/a.svg" $STOCK)" = 6 ] && echo ok || echo no)"
check "a user following the default gets protoss's colours" "$([ "$(has "$T/u.svg" $PROTOSS)" = 6 ] && echo ok || echo no)"
echo "== outside a web request (alert emails and chat messages are drawn by the alert process)"
cli_svg > "$T/cli-protoss.svg"
check "a CLI process under a protoss default draws protoss's six colours" "$([ "$(has "$T/cli-protoss.svg" $PROTOSS)" = 6 ] && echo ok || echo no)"
check "and none of the stock series colours" "$([ "$(has "$T/cli-protoss.svg" $STOCK)" = 0 ] && echo ok || echo no)"
set_default ""
choose dev-admin ""
cli_svg > "$T/cli-stock.svg"
check "with no default a CLI process draws stock" "$([ "$(has "$T/cli-stock.svg" $STOCK)" = 6 ] && echo ok || echo no)"
svg dev-user > "$T/after.svg"
check "clearing the default puts the following user back on stock" "$([ "$(has "$T/after.svg" $STOCK)" = 6 ] && echo ok || echo no)"

[ "$FAILED" = 0 ] && echo "all checks passed" || { echo "SOME CHECKS FAILED"; exit 1; }
