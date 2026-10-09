#!/bin/sh
# The update path, rehearsed on a clean LibreNMS (no dev bind mount): install, the nightly update,
# the night the source is unreachable, scripts/update.sh, the status command, and uninstall.
#
#   sh dev/test-update.sh                 (from WSL or Linux with Docker; about four minutes)
#   KEEP_STACK=1 sh dev/test-update.sh    leave the throwaway stack up afterwards
#
# It starts its own stack (dev/compose-clean.yml), separate from the dev instance, and removes it
# afterwards. The package's git source is a local repository the test builds from THIS working
# tree (uncommitted changes included): main starts at an old commit, and the nightly update moves
# it forward, as a merge to main does on a real host. LibreNMS's own `daily.sh post-pull` does the
# update, so what is checked is what LibreNMS does, not a copy of it.
#
# TS_NETWORK=1 adds a section against the real GitHub: a night it cannot be reached with a warm
# Composer cache (the plugin stays) and with a cold one (it is removed; the safety net,
# scripts/ensure-installed.sh, restores it).
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
compose() { docker compose -f "$ROOT/dev/compose-clean.yml" "$@"; }
C=theme-selector-clean-librenms-1
OLD=f84876e # main as of the merge of PR 26: before light/dark (one migration fewer), before versions were recorded
FAILED=0
STATUS_OUT="$(mktemp)"
UPDATE_OUT="$(mktemp)"

check() { if [ "$2" = ok ]; then echo "  ok    $1"; else echo "  FAIL  $1"; FAILED=1; fi; }
yes_if() { if eval "$1"; then echo ok; else echo no; fi; }
section() { echo; echo "== $1"; }

# inr: as root in the container. inl: as the librenms user, in /opt/librenms, with Composer's home set.
inr() { docker exec "$C" sh -c "$1"; }
inl() { docker exec "$C" sh -c "cd /opt/librenms && gosu librenms:librenms env COMPOSER_HOME=/data/composer $1"; }
sql() { docker exec "$C" mariadb -h db -u librenms -pclean-pass librenms -N -e "$1"; }

migs() { sql "select count(*) from migrations where migration like '%theme_selector%'"; }
cfgrows() { sql "select count(*) from config where config_name like 'graph_colours.%' or config_name like 'rrdgraph_def_text%'"; }
leftovers() { sql "select (select count(*) from information_schema.tables where table_schema = 'librenms' and table_name like 'theme_selector%') + (select count(*) from users_prefs where pref like 'theme_selector%') + (select count(*) from migrations where migration like '%theme_selector%') + (select count(*) from plugins where plugin_name = 'ThemeSelector')"; }
routes() { inl "php artisan route:list 2>/dev/null | grep -c $1"; }
nvendor() { inr 'ls /opt/librenms/vendor/xblossia 2>/dev/null | wc -l'; }
plugins_json() { inr 'cat /opt/librenms/composer.plugins.json'; }
locked() { inr 'grep -A8 "\"name\": \"xblossia/librenms-theme-selector\"" /opt/librenms/composer.lock | grep reference | cut -d\" -f4 | cut -c1-7'; }
web() { inr 'curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/login'; }
status() { inl './lnms theme-selector:status' >"$STATUS_OUT" 2>&1; }
# What daily.sh does before and during its post-pull phase: clear caches, put composer's files back to
# stock (it does `git checkout` of them), then its own `post-pull`, which re-requires the plugins listed in
# composer.plugins.json, runs `composer install`, and migrates. php-fpm is then reloaded: the image keeps
# compiled PHP for 60 seconds (opcache.revalidate_freq), so without it the first requests after an update
# would still run the old code, which is true of a real host for as long as its own setting says.
nightly() { inr 'cd /opt/librenms && cp /data/pristine/composer.json /data/pristine/composer.lock . && chown librenms:librenms composer.json composer.lock && php artisan optimize:clear >/dev/null 2>&1; gosu librenms:librenms env COMPOSER_HOME=/data/composer ./daily.sh post-pull old new 2>&1; pkill -USR2 -f "php-fpm: master process"; sleep 3'; }
move_main() { inr "git --git-dir=/data/ts.git update-ref refs/heads/main $1; chown -R librenms:librenms /data/ts.git"; }

cleanup() {
  rm -f "$STATUS_OUT" "$UPDATE_OUT"
  if [ -z "${KEEP_STACK:-}" ]; then compose down -v >/dev/null 2>&1; fi
}
trap cleanup EXIT

git -C "$ROOT" cat-file -e "$OLD" 2>/dev/null || { echo "commit $OLD is not in this repository's history"; exit 2; }

section "a clean LibreNMS"
compose down -v >/dev/null 2>&1
compose up -d >/dev/null 2>&1 || compose up -d || { echo "could not start the stack"; exit 2; }
ready=no
for i in $(seq 1 90); do
  if inl 'php artisan migrate:status >/dev/null 2>&1' 2>/dev/null && [ "$(web 2>/dev/null)" != 000 ]; then ready=yes; break; fi
  sleep 3
done
check "the stack came up" "$(yes_if '[ "$ready" = yes ]')"
[ "$ready" = yes ] || exit 1
inr 'sed -i "/^INSTALL=/d" /opt/librenms/.env; cd /opt/librenms && git config --global --add safe.directory "*"; mkdir -p /data/pristine && cp composer.json composer.lock /data/pristine/'
inl './lnms user:add --no-interaction -r admin -p "pw-$(date +%s)-clean" tester >/dev/null 2>&1'
check "no plugin is installed" "$(yes_if '[ "$(nvendor)" = 0 ]')"

section "a git source: main at an old commit, and this working tree as three later commits"
inr "rm -rf /data/ts.git /data/work; git clone -q --bare /plugin /data/ts.git; git --git-dir=/data/ts.git update-ref refs/heads/main $OLD; git --git-dir=/data/ts.git symbolic-ref HEAD refs/heads/main; git clone -q /data/ts.git /data/work"
inr 'cd /data/work && git rm -rq . && tar -C /plugin --exclude=.git --exclude=.claude --exclude=__pycache__ -cf - . | tar -C /data/work -xf - && git add -A && git -c user.name=t -c user.email=t@example.test commit -q -m "this working tree" && git push -q /data/ts.git HEAD:refs/heads/next'
inr 'cd /data/work && echo one > UPDATE_TEST.txt && git add -A && git -c user.name=t -c user.email=t@example.test commit -q -m "a later commit" && git push -q /data/ts.git HEAD:refs/heads/next2'
inr 'cd /data/work && echo two > UPDATE_TEST.txt && git add -A && git -c user.name=t -c user.email=t@example.test commit -q -m "and another" && git push -q /data/ts.git HEAD:refs/heads/next3 && chown -R librenms:librenms /data/ts.git'
NEXT="$(inr 'git --git-dir=/data/ts.git rev-parse --short=7 next')"
NEXT2="$(inr 'git --git-dir=/data/ts.git rev-parse --short=7 next2')"
NEXT3="$(inr 'git --git-dir=/data/ts.git rev-parse --short=7 next3')"
check "the source has an old main and three newer commits" "$(yes_if '[ -n "$NEXT" ] && [ -n "$NEXT2" ] && [ -n "$NEXT3" ] && [ "$NEXT" != "$NEXT2" ] && [ "$NEXT2" != "$NEXT3" ]')"

section "install (the steps in docs/DEPLOYMENT.md, with the local source in place of GitHub)"
inl 'php scripts/composer_wrapper.php config --global repositories.theme-selector vcs /data/ts.git'
inl './lnms plugin:add xblossia/librenms-theme-selector dev-main >/tmp/add.log 2>&1'; rc=$?
check "plugin:add succeeds" "$(yes_if "[ $rc = 0 ]")"
inl './lnms migrate --force >/dev/null 2>&1'
inl 'php artisan route:cache >/dev/null 2>&1'
inl './lnms theme-selector:publish >/dev/null 2>&1'
check "composer.plugins.json records it, for daily.sh" "$(yes_if 'plugins_json | grep -q "librenms-theme-selector.*dev-main"')"
check "it installed the old commit" "$(yes_if '[ "$(locked)" = "$OLD" ]')"
check "its migrations ran (four, at the old commit)" "$(yes_if '[ "$(migs)" = 4 ]')"
check "the picker page is routed" "$(yes_if '[ "$(routes theme-selector.index)" = 1 ]')"
check "the base stylesheet is published" "$(yes_if 'inr "test -f /opt/librenms/html/css/custom/theme-selector/base.css"')"

section "the nightly update: LibreNMS's daily.sh post-pull, main having moved"
move_main "$NEXT"
OUT="$(nightly)"
check "daily.sh's composer steps report OK" "$(yes_if 'echo "$OUT" | grep -q "Updating Composer packages.*OK"')"
check "the lock moved to the new commit" "$(yes_if '[ "$(locked)" = "$NEXT" ]')"
EXPECT="$(inr 'ls /opt/librenms/vendor/xblossia/librenms-theme-selector/database/migrations | wc -l')"
check "its new migration ran ($EXPECT in all)" "$(yes_if '[ "$(migs)" = "$EXPECT" ]')"
check "LibreNMS rebuilt its route cache with the plugin in it" "$(yes_if '[ "$(routes theme-selector.preview)" = 1 ]')"
check "it is still enabled and a page renders" "$(yes_if '[ "$(web)" = 200 ]')"
check "the first page load published the light base stylesheet" "$(yes_if 'inr "test -f /opt/librenms/html/css/custom/theme-selector/base-light.css"')"
status; rc=$?
check "theme-selector:status passes" "$(yes_if "[ $rc = 0 ] && ! grep -q '^FAIL\|^WARN' $STATUS_OUT")"
check "and says which version is installed" "$(yes_if "grep -q 'installed: dev-main@$NEXT' $STATUS_OUT")"

section "a second night: the log says an update landed"
move_main "$NEXT2"
OUT="$(nightly)"
check "the lock moved again" "$(yes_if '[ "$(locked)" = "$NEXT2" ]')"
web >/dev/null
check "the next page load logged the update" "$(yes_if "inr 'grep -c \"ThemeSelector: updated from dev-main@$NEXT to dev-main@$NEXT2\" /opt/librenms/logs/librenms.log' | grep -qv '^0'")"
[ "$(inr "grep -c 'ThemeSelector: updated from' /opt/librenms/logs/librenms.log")" != 0 ] || { echo "        log lines:"; inr "grep ThemeSelector /opt/librenms/logs/librenms.log | tail -5; cat /opt/librenms/html/css/custom/theme-selector/.bundled.json | grep -E 'version|published_at'; grep -E 'opcache.(validate|revalidate)' /etc/php*/conf.d/*.ini" | sed 's/^/        /'; }
status
check "status shows the new commit" "$(yes_if "grep -q 'installed: dev-main@$NEXT2' $STATUS_OUT")"

section "a night the source cannot be reached, with nothing cached (the local source has no Composer cache)"
inr 'mkdir -p /usr/local/sbin && install -m 0755 -o root -g root /opt/librenms/vendor/xblossia/librenms-theme-selector/scripts/ensure-installed.sh /usr/local/sbin/theme-selector-ensure.sh'
OUT="$(inl '/usr/local/sbin/theme-selector-ensure.sh' 2>&1)"; rc=$?
check "the safety net does nothing, silently, while the plugin is installed" "$(yes_if "[ $rc = 0 ] && [ -z \"\$OUT\" ]")"
inr 'mv /data/ts.git /data/ts.git.away'
OUT="$(nightly)"
check "composer's require fails, so the plugin is uninstalled (and daily.sh still says OK)" "$(yes_if '[ "$(nvendor)" = 0 ] && echo "$OUT" | grep -q "Updating Composer packages.*OK"')"
check "LibreNMS carries on without it: a page still renders" "$(yes_if '[ "$(web)" = 200 ]')"
check "composer.plugins.json still lists it, so the next good night brings it back" "$(yes_if 'plugins_json | grep -q librenms-theme-selector')"
OUT="$(inl '/usr/local/sbin/theme-selector-ensure.sh' 2>&1)"; rc=$?
check "the safety net, with the source still away, fails with exit 1 and says so" "$(yes_if "[ $rc = 1 ] && echo \"\$OUT\" | grep -q 'plugin:add failed'")"
check "and changed nothing: no plugin, LibreNMS's composer files as they were" "$(yes_if '[ "$(nvendor)" = 0 ] && inr "cmp -s /opt/librenms/composer.json /data/pristine/composer.json && cmp -s /opt/librenms/composer.lock /data/pristine/composer.lock"')"
check "it wrote a line in LibreNMS's log directory" "$(yes_if 'inr "grep -q \"plugin:add failed\" /opt/librenms/logs/theme-selector-ensure.log"')"
inr 'mv /data/ts.git.away /data/ts.git'
OUT="$(inl '/usr/local/sbin/theme-selector-ensure.sh' 2>&1)"; rc=$?
check "with the source back it restores the plugin (exit 0, says so)" "$(yes_if "[ $rc = 0 ] && echo \"\$OUT\" | grep -q 'restored xblossia/librenms-theme-selector (dev-main)' && [ \"\$(locked)\" = \"$NEXT2\" ] && [ \"\$(nvendor)\" = 1 ]")"
inr 'pkill -USR2 -f "php-fpm: master process"; sleep 3'
check "the plugin works again: a page renders and its commands exist" "$(yes_if '[ "$(web)" = 200 ] && [ "$(routes theme-selector.index)" = 1 ]')"
OUT="$(inl '/usr/local/sbin/theme-selector-ensure.sh' 2>&1)"; rc=$?
check "run again it does nothing, silently" "$(yes_if "[ $rc = 0 ] && [ -z \"\$OUT\" ]")"
OUT="$(nightly)"
check "the next night, with the source back, keeps it" "$(yes_if '[ "$(locked)" = "$NEXT2" ] && [ "$(web)" = 200 ]')"
OUT="$(docker exec "$C" sh -c '/usr/local/sbin/theme-selector-ensure.sh' 2>&1)"; rc=$?
check "the safety net run as root refuses (exit 2)" "$(yes_if "[ $rc = 2 ]")"

section "scripts/update.sh"
move_main "$NEXT3"
inr 'cd /opt/librenms && vendor/xblossia/librenms-theme-selector/scripts/update.sh >/dev/null 2>&1'; rc=$?
check "run as root it refuses" "$(yes_if "[ $rc = 2 ]")"
docker exec -u librenms "$C" sh -c 'cd /opt/librenms && COMPOSER_HOME=/data/composer vendor/xblossia/librenms-theme-selector/scripts/update.sh' >"$UPDATE_OUT" 2>&1; rc=$?
check "run as librenms it succeeds" "$(yes_if "[ $rc = 0 ]")"
[ "$rc" = 0 ] || sed 's/^/        /' "$UPDATE_OUT" | tail -25
check "it follows what the plugin follows, and moved it to the newest commit" "$(yes_if "[ \"\$(locked)\" = \"$NEXT3\" ] && grep -q 'plugin:add xblossia/librenms-theme-selector dev-main' $UPDATE_OUT")"
check "it ends with the status checks, all passing" "$(yes_if "grep -q '^ok    installed: dev-main@$NEXT3' $UPDATE_OUT && ! grep -q '^FAIL\|^WARN' $UPDATE_OUT")"

section "following releases: a host on ^1.0 takes tags, not commits on main"
inr 'cd /data/work && echo three > UPDATE_TEST.txt && git add -A && git -c user.name=t -c user.email=t@example.test commit -q -m "a major change" && git push -q /data/ts.git HEAD:refs/heads/next4 && chown -R librenms:librenms /data/ts.git'
NEXT4="$(inr 'git --git-dir=/data/ts.git rev-parse --short=7 next4')"
tag() { inr "git --git-dir=/data/ts.git tag $1 $2 && chown -R librenms:librenms /data/ts.git"; }
tag v1.0.0 "$NEXT"
tag v1.0.1 "$NEXT2"
# main is at NEXT3, ahead of the newest tag: a host on ^1.0 must not take it.
inl "./lnms plugin:add xblossia/librenms-theme-selector '^1.0' >/dev/null 2>&1"; rc=$?
check "plugin:add with ^1.0 succeeds" "$(yes_if "[ $rc = 0 ]")"
check "it installed the newest tag (v1.0.1), not main, which is ahead of it" "$(yes_if '[ "$(locked)" = "$NEXT2" ]')"
check "composer.plugins.json now records ^1.0, for daily.sh" "$(yes_if 'plugins_json | grep -q "librenms-theme-selector.*\^1.0"')"
status; rc=$?
check "status passes, says it follows releases matching ^1.0, and shows the version" "$(yes_if "[ $rc = 0 ] && grep -q 'releases matching \^1.0' $STATUS_OUT && grep -Eq 'installed: v?1\.0\.1 \($NEXT2\)' $STATUS_OUT")"
OUT="$(nightly)"
check "a nightly update leaves it on v1.0.1 while main is ahead" "$(yes_if '[ "$(locked)" = "$NEXT2" ]')"
tag v1.1.0 "$NEXT3"
OUT="$(nightly)"
check "a new minor release (v1.1.0) is taken the next night" "$(yes_if '[ "$(locked)" = "$NEXT3" ]')"
tag v2.0.0 "$NEXT4"
OUT="$(nightly)"
check "a new major release (v2.0.0) is not taken: ^1.0 stays on v1.1.0" "$(yes_if '[ "$(locked)" = "$NEXT3" ]')"
docker exec -u librenms "$C" sh -c 'cd /opt/librenms && COMPOSER_HOME=/data/composer vendor/xblossia/librenms-theme-selector/scripts/update.sh' >"$UPDATE_OUT" 2>&1; rc=$?
check "update.sh keeps following ^1.0 (it asked for ^1.0, and stayed on v1.1.0)" "$(yes_if "[ $rc = 0 ] && grep -q 'plugin:add xblossia/librenms-theme-selector ^1.0' $UPDATE_OUT && [ \"\$(locked)\" = \"$NEXT3\" ]")"

section "what theme-selector:status catches"
inr 'cp /opt/librenms/composer.plugins.json /data/plugins.json.keep && echo "{\"require\":{}}" > /opt/librenms/composer.plugins.json'
status; rc=$?
check "a plugin missing from composer.plugins.json fails, saying daily.sh would remove it" "$(yes_if "[ $rc != 0 ] && grep -q '^FAIL.*removes this one' $STATUS_OUT")"
inr 'cp /data/plugins.json.keep /opt/librenms/composer.plugins.json'
sql "delete from migrations where migration like '%add_mode_and_family%'" >/dev/null
status; rc=$?
check "a migration that has not run fails, naming it" "$(yes_if "[ $rc != 0 ] && grep -q '^FAIL.*add_mode_and_family' $STATUS_OUT")"
sql "insert into migrations (migration, batch) select '2026_10_03_000001_add_mode_and_family_to_theme_selector_skins', max(batch) from migrations" >/dev/null
inr 'chmod 555 /opt/librenms/html/css/custom/theme-selector'
status; rc=$?
check "a published directory the web user cannot write fails" "$(yes_if "[ $rc != 0 ] && grep -q '^FAIL.*not writable' $STATUS_OUT")"
inr 'chmod 755 /opt/librenms/html/css/custom/theme-selector'
status; rc=$?
check "and passes again once they are put back" "$(yes_if "[ $rc = 0 ]")"

section "uninstall (the steps in docs/DEPLOYMENT.md)"
inl "php artisan tinker --execute='\$d = app(Xblossia\\ThemeSelector\\DefaultSkin::class); \$d->set(\"terran\", \"dark\"); \$d->set(\"clock-tower-daylight\", \"light\");' >/dev/null 2>&1"
sql "insert into users_prefs (user_id, pref, value) values (1, 'theme_selector.skin', 'zerg'), (1, 'theme_selector.skin_light', 'clock-tower-daylight')" >/dev/null
check "defaults for both modes write graph colours" "$(yes_if '[ "$(cfgrows)" -gt 0 ]')"
inl "php artisan tinker --execute='\$d = app(Xblossia\\ThemeSelector\\DefaultSkin::class); \$d->set(null, \"dark\"); \$d->set(null, \"light\");' >/dev/null 2>&1"
check "clearing both restores them (none left)" "$(yes_if '[ "$(cfgrows)" = 0 ]')"
inl './lnms plugin:remove xblossia/librenms-theme-selector >/dev/null 2>&1'
inl 'rm -rf html/css/custom/theme-selector; php artisan route:cache >/dev/null 2>&1; php scripts/composer_wrapper.php config --global --unset repositories.theme-selector'
OUT="$(inl '/usr/local/sbin/theme-selector-ensure.sh' 2>&1)"; rc=$?
check "after plugin:remove the safety net leaves it removed (it was taken out of composer.plugins.json)" "$(yes_if "[ $rc = 0 ] && [ -z \"\$OUT\" ] && [ \"\$(nvendor)\" = 0 ]")"
check "the package and its routes are gone" "$(yes_if '[ "$(nvendor)" = 0 ] && [ "$(routes theme-selector)" = 0 ]')"
check "a page renders" "$(yes_if '[ "$(web)" = 200 ]')"
sql "DROP TABLE theme_selector_settings; DROP TABLE theme_selector_skins; DELETE FROM users_prefs WHERE pref LIKE 'theme_selector.%'; DELETE FROM migrations WHERE migration LIKE '%theme_selector%'; DELETE FROM plugins WHERE plugin_name = 'ThemeSelector';" >/dev/null
check "the data steps leave no table, preference, migration row or plugin row" "$(yes_if '[ "$(leftovers)" = 0 ]')"

if [ -n "${TS_NETWORK:-}" ]; then
  section "GitHub itself (TS_NETWORK=1): an outage with a warm Composer cache, then a cold one"
  git_down() { inr 'git config --system url."http://127.0.0.1:9/".insteadOf https://github.com/'; }
  git_up() { inr 'git config --system --unset url."http://127.0.0.1:9/".insteadOf'; }
  inl 'php scripts/composer_wrapper.php config --global repositories.theme-selector "{\"type\":\"vcs\",\"url\":\"https://github.com/XBLOssia/librenms-theme-selector\",\"no-api\":true}"'
  inl './lnms plugin:add xblossia/librenms-theme-selector dev-main >/dev/null 2>&1'
  inl './lnms migrate --force >/dev/null 2>&1'
  inl './lnms theme-selector:publish >/dev/null 2>&1'
  nightly >/dev/null
  # GitHub's main only has the status command once this work is merged; until then those two checks are skipped.
  have_status="$(inl './lnms list 2>/dev/null | grep -c theme-selector:status')"
  if [ "$have_status" != 0 ]; then
    status; rc=$?
    check "installed from GitHub in git mode, status passes and sees the cache" "$(yes_if "[ $rc = 0 ] && ! grep -q '^FAIL\|^WARN' $STATUS_OUT && grep -q 'repository cache: present' $STATUS_OUT")"
    [ "$rc" = 0 ] && ! grep -q '^FAIL\|^WARN' "$STATUS_OUT" || sed 's/^/        /' "$STATUS_OUT"
  else
    echo "  skip  status checks (GitHub's main has no status command yet)"
  fi
  git_down
  OUT="$(nightly)"
  check "GitHub unreachable, cache warm: Composer warns it may be outdated and the plugin stays" "$(yes_if 'echo "$OUT" | grep -q "package information from this repository may be outdated" && [ "$(nvendor)" = 1 ]')"
  inr 'rm -rf /data/composer/cache'
  if [ "$have_status" != 0 ]; then
    status
    check "with the cache gone, status warns that an outage would remove the plugin" "$(yes_if "grep -q '^WARN.*repository cache' $STATUS_OUT")"
  fi
  OUT="$(nightly)"
  check "GitHub unreachable, cache cold: the plugin is removed (the case the safety net is for)" "$(yes_if '[ "$(nvendor)" = 0 ]')"
  OUT="$(inl '/usr/local/sbin/theme-selector-ensure.sh' 2>&1)"; rc=$?
  check "the safety net cannot restore it while GitHub is down, and says so" "$(yes_if "[ $rc = 1 ] && [ \"\$(nvendor)\" = 0 ]")"
  git_up
  OUT="$(inl '/usr/local/sbin/theme-selector-ensure.sh' 2>&1)"; rc=$?
  check "once GitHub is back it restores the plugin" "$(yes_if "[ $rc = 0 ] && [ \"\$(nvendor)\" = 1 ]")"
fi

echo
if [ "$FAILED" = 0 ]; then echo "all checks passed"; else echo "SOME CHECKS FAILED"; fi
exit "$FAILED"
