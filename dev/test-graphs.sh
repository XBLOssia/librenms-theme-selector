#!/bin/sh
# Per-user graph colours, end to end, against the dev instance.
#
#   docker exec theme-selector-dev-librenms-1 sh /plugin/dev/test-graphs.sh
#
# Builds a dummy device, port and synthetic RRD (idempotent), then draws the
# same two graphs, over a fixed time window so equal colours mean identical
# bytes, as users with different skin choices under different instance
# defaults. Exits non-zero on the first failed expectation.
set -u

B=http://127.0.0.1:8000
DB="mariadb -h db -u librenms -plibrenms-dev librenms -N -e"
HOST=graphtest.example.net
RRDDIR=/data/rrd/$HOST
FAILED=0

q() { $DB "$1"; }
cfg() { gosu librenms php /opt/librenms/lnms config:get "$1" 2>/dev/null | tr -d '\n '; }

# --- fixture -----------------------------------------------------------------
q "insert into devices (hostname, sysName, status, status_reason, os)
   select '$HOST', 'graphtest', 1, '', 'linux' from dual
   where not exists (select 1 from devices where hostname='$HOST')"
DID=$(q "select device_id from devices where hostname='$HOST'")
q "insert into ports (device_id, ifIndex, ifName, ifDescr, ifAlias, ifSpeed)
   select $DID, 1, 'Te1/0/1', 'Te1/0/1', 'uplink', 10000000000 from dual
   where not exists (select 1 from ports where device_id=$DID)"
PID=$(q "select port_id from ports where device_id=$DID")

RRD=$RRDDIR/port-id$PID.rrd
if [ ! -f "$RRD" ]; then
  mkdir -p "$RRDDIR"
  END=$(date +%s); START=$((END - 90000))
  ds=""
  for n in INOCTETS OUTOCTETS INERRORS OUTERRORS INUCASTPKTS OUTUCASTPKTS INNUCASTPKTS OUTNUCASTPKTS \
           INDISCARDS OUTDISCARDS INUNKNOWNPROTOS INBROADCASTPKTS OUTBROADCASTPKTS INMULTICASTPKTS OUTMULTICASTPKTS; do
    ds="$ds DS:$n:COUNTER:600:0:12500000000"
  done
  # shellcheck disable=SC2086
  rrdtool create "$RRD" --start $((START - 300)) --step 300 $ds RRA:AVERAGE:0.5:1:600 RRA:MAX:0.5:1:600
  t=$START; i=0; a=0; b=0; e1=0; e2=0
  while [ $t -lt $END ]; do
    a=$((a + 30000000 + (i % 13) * 2000000)); b=$((b + 9000000 + (i % 7) * 1500000))
    e1=$((e1 + 20 + (i % 5) * 9)); e2=$((e2 + 5 + (i % 3) * 6))
    rrdtool update "$RRD" "$t:$a:$b:$e1:$e2:0:0:0:0:0:0:0:0:0:0:0" >/dev/null
    t=$((t + 300)); i=$((i + 1))
  done
  chown -R librenms:librenms /data/rrd
fi
LAST=$(rrdtool last "$RRD"); FROM=$((LAST - 86400))

# dev-user is a plain user; without a device permission its graph requests get
# an (identical) error image, which would make every comparison meaningless.
q "insert ignore into devices_perms (user_id, device_id)
   select user_id, $DID from users where username='dev-user'"

# The dark theme is what makes graphs use the *_dark chrome colours.
for u in dev-admin dev-user; do
  uid=$(q "select user_id from users where username='$u'")
  q "insert into users_prefs (user_id, pref, value) values ($uid, 'site_style', 'dark')
     on duplicate key update value='dark'"
done

# --- helpers -----------------------------------------------------------------
jar() { echo "/tmp/jar-$1"; }
login() { rm -f "$(jar "$1")"; curl -s -o /dev/null -c "$(jar "$1")" -b "$(jar "$1")" -H "X-Dev-User: $1" $B/devices; }
post() { # user path data
  tok=$(curl -s -c "$(jar "$1")" -b "$(jar "$1")" -H "X-Dev-User: $1" $B/plugin/theme-selector \
        | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
  curl -s -o /dev/null -c "$(jar "$1")" -b "$(jar "$1")" -H "X-Dev-User: $1" --data "_token=$tok&$3" "$B$2"
}
choose() { post "$1" /plugin/theme-selector "skin=$2"; }
set_default() { post dev-admin /plugin/theme-selector/default "default=$1"; }
graph() { # user type
  curl -s -b "$(jar "$1")" -H "X-Dev-User: $1" \
    "$B/graph.php?type=$2&id=$PID&from=$FROM&to=$LAST&width=300&height=120" | sha256sum | cut -c1-10
}
check() { # description, condition result
  if [ "$2" = ok ]; then echo "  ok    $1"; else echo "  FAIL  $1"; FAILED=1; fi
}
same() { [ "$1" = "$2" ] && echo ok || echo no; }
differ() { [ "$1" != "$2" ] && echo ok || echo no; }

# Two sessions, deliberately different so one user's request can't be mistaken
# for the other's: dev-admin plays "user A", dev-user plays "user B".
login dev-admin; login dev-user
set_default ""

echo "== default: none (config holds stock)"
STOCK_GREENS=$(cfg graph_colours.greens)
for T in port_bits port_errors; do
  choose dev-admin zerg;   ZERG=$(graph dev-admin $T)
  choose dev-admin protoss; PROT=$(graph dev-admin $T)
  choose dev-admin none;   NONE=$(graph dev-admin $T)
  choose dev-user "";      FOLLOW=$(graph dev-user $T)
  echo "  $T: zerg=$ZERG protoss=$PROT stock=$NONE follow-default=$FOLLOW"
  check "$T: zerg and protoss graphs differ" "$(differ "$ZERG" "$PROT")"
  check "$T: a skin differs from stock" "$(differ "$ZERG" "$NONE")"
  check "$T: a user following an empty default gets stock" "$(same "$FOLLOW" "$NONE")"
  eval "Z_$T=$ZERG; N_$T=$NONE; P_$T=$PROT"
done
check "no leak: persistent greens unchanged after skin graph requests" "$(same "$(cfg graph_colours.greens)" "$STOCK_GREENS")"

echo "== default: terran (config now holds terran's palette)"
set_default terran
check "persistent config holds terran's palette" "$(differ "$(cfg graph_colours.greens)" "$STOCK_GREENS")"
for T in port_bits port_errors; do
  choose dev-admin zerg;    ZERG2=$(graph dev-admin $T)
  choose dev-admin none;    NONE2=$(graph dev-admin $T)
  choose dev-user "";       TERR=$(graph dev-user $T)
  choose dev-admin terran;  TERR2=$(graph dev-admin $T)
  ZERG_BEFORE=$(eval echo \$Z_$T); NONE_BEFORE=$(eval echo \$N_$T)
  echo "  $T: zerg=$ZERG2 stock=$NONE2 follow-default=$TERR terran-chosen=$TERR2"
  check "$T: zerg looks the same whatever the default is" "$(same "$ZERG2" "$ZERG_BEFORE")"
  check "$T: explicit stock still gets stock graphs" "$(same "$NONE2" "$NONE_BEFORE")"
  check "$T: following the default equals choosing it" "$(same "$TERR" "$TERR2")"
  check "$T: terran differs from zerg" "$(differ "$TERR" "$ZERG2")"
  # order independence: a zerg request must not leave anything behind
  choose dev-admin zerg; graph dev-admin "$T" >/dev/null
  AGAIN=$(graph dev-user $T)
  check "$T: another user's request afterwards is unaffected" "$(same "$AGAIN" "$TERR")"
done

echo "== default cleared"
set_default ""
check "persistent greens back to stock (before the light-mode section)" "$(same "$(cfg graph_colours.greens)" "$STOCK_GREENS")"

echo "== light mode: each mode's graphs follow that mode's skin, and a palette applies in the mode its skin is written for"
FXG=/tmp/ts-fixtures-graphs; rm -rf "$FXG"; php /plugin/dev/make-fixtures.php "$FXG" >/dev/null
upload_skin() { # file
  tok=$(curl -s -c "$(jar dev-admin)" -b "$(jar dev-admin)" -H "X-Dev-User: dev-admin" $B/plugin/theme-selector | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
  curl -s -o /dev/null -c "$(jar dev-admin)" -b "$(jar dev-admin)" -H "X-Dev-User: dev-admin" -F "_token=$tok" -F "bundle=@$1" $B/plugin/theme-selector/skins
}
upload_skin "$FXG/good-light.zip"
check "a light skin (paper-teal) is installed" "$([ "$(q "select mode from theme_selector_skins where id='paper-teal'")" = light ] && echo ok || echo no)"
gstyle() { # user type style
  curl -s -b "$(jar "$1")" -H "X-Dev-User: $1" \
    "$B/graph.php?type=$2&id=$PID&from=$FROM&to=$LAST&width=300&height=120&style=$3" | sha256sum | cut -c1-10
}
for T in port_bits port_errors; do
  post dev-admin /plugin/theme-selector "skin=none&skin_light=none"
  L_STOCK=$(gstyle dev-admin $T light); D_STOCK=$(gstyle dev-admin $T dark)
  post dev-admin /plugin/theme-selector "skin=none&skin_light=paper-teal"
  L_PAPER=$(gstyle dev-admin $T light); D_WITH_LIGHT=$(gstyle dev-admin $T dark)
  post dev-admin /plugin/theme-selector "skin=paper-teal&skin_light=none"
  L_PAPER_DARKSLOT=$(gstyle dev-admin $T light); D_PAPER_DARKSLOT=$(gstyle dev-admin $T dark)
  post dev-admin /plugin/theme-selector "skin=zerg&skin_light=zerg"
  L_ZERG_LIGHTSLOT=$(gstyle dev-admin $T light); D_ZERG=$(gstyle dev-admin $T dark)
  echo "  $T: light stock=$L_STOCK paper=$L_PAPER | dark stock=$D_STOCK zerg=$D_ZERG"
  check "$T: a light skin in the light slot changes light graphs" "$(differ "$L_PAPER" "$L_STOCK")"
  check "$T: and leaves dark graphs alone (stock)" "$(same "$D_WITH_LIGHT" "$D_STOCK")"
  check "$T: a light skin in the dark slot changes nothing: its palette is for light graphs" "$(same "$D_PAPER_DARKSLOT" "$D_STOCK")"
  check "$T: ... and light graphs follow the light slot (empty here), so stock" "$(same "$L_PAPER_DARKSLOT" "$L_STOCK")"
  check "$T: a dark skin in the light slot changes nothing in light graphs (its palette is for dark graphs)" "$(same "$L_ZERG_LIGHTSLOT" "$L_STOCK")"
  check "$T: and in the dark slot it still changes dark graphs" "$(differ "$D_ZERG" "$D_STOCK")"
done
check "no leak: persistent greens unchanged by light-mode requests" "$(same "$(cfg graph_colours.greens)" "$STOCK_GREENS")"
set_default_light() { post dev-admin /plugin/theme-selector/default "default_light=$1"; }
post dev-admin /plugin/theme-selector "skin=none&skin_light=none"
L_STOCK=$(gstyle dev-admin port_bits light); D_STOCK=$(gstyle dev-admin port_bits dark)   # the loop above ended on port_errors
set_default_light paper-teal
check "a light default writes its palette into the persistent config (light chrome and ramps)" "$([ "$(cfg rrdgraph_def_text_color)" = 332200 ] && [ "$(cfg graph_colours.greens)" != "$STOCK_GREENS" ] && echo ok || echo no)"
check "and not the dark chrome" "$([ "$(cfg rrdgraph_def_text_color_dark)" != 332200 ] && echo ok || echo no)"
post dev-user /plugin/theme-selector "skin=&skin_light="
L_DEFAULT=$(gstyle dev-user port_bits light); D_DEFAULT=$(gstyle dev-user port_bits dark)
check "a user following the default sees it in light graphs and not in dark ones" "$(differ "$L_DEFAULT" "$D_STOCK")"
check "...dark graphs stay stock" "$(same "$D_DEFAULT" "$D_STOCK")"
post dev-user /plugin/theme-selector "skin=none&skin_light=none"
check "a user who chose stock for light gets stock light graphs despite the default" "$(same "$(gstyle dev-user port_bits light)" "$L_STOCK")"
set_default_light ""
check "clearing the light default restores the config" "$(same "$(cfg graph_colours.greens)" "$STOCK_GREENS")"
check "no graph_colours/rrdgraph override rows left" "$([ "$(q "select count(*) from config where config_name like 'graph_colours.%' or config_name like 'rrdgraph_def_text%'")" = 0 ] && echo ok || echo no)"
post dev-admin /plugin/theme-selector "skin=&skin_light="
post dev-user /plugin/theme-selector "skin=&skin_light="
post dev-admin /plugin/theme-selector/skins/paper-teal/delete ""
rm -rf "$FXG"
check "persistent greens back to stock" "$(same "$(cfg graph_colours.greens)" "$STOCK_GREENS")"
check "no graph_colours/rrdgraph override rows left" \
  "$([ "$(q "select count(*) from config where config_name like 'graph_colours.%' or config_name like 'rrdgraph_def_text%'")" = 0 ] && echo ok || echo no)"

echo
if [ $FAILED = 0 ]; then echo "all checks passed"; else echo "SOME CHECKS FAILED"; fi
exit $FAILED
