#!/bin/sh
# Put Theme Selector back if LibreNMS's nightly update has removed it. A safety net, optional.
#
# What it guards against: daily.sh resets composer.lock to LibreNMS's own, asks Composer to add every
# plugin in composer.plugins.json back, then runs `composer install`. If that request fails (the
# source can't be reached and Composer has no cached copy, or a bad commit made the require fail),
# the install finds no plugin in the lock and removes it. daily.sh still reports OK, pages go stock,
# and the plugin comes back only when a later night succeeds. This runs between nights: if the plugin
# is listed in composer.plugins.json but is not installed, it runs the same `plugin:add` an
# administrator would. See docs/DEPLOYMENT.md, "Updates".
#
# What it will NOT do: bring back a plugin that was removed on purpose (`lnms plugin:remove` takes it
# out of composer.plugins.json, and then this does nothing), run while daily.sh or Composer is
# running, or do anything at all when the plugin is installed (the usual case: one stat call).
#
# It has to live outside the package, since the package is what is missing when it matters. Install
# it root-owned and run it as the librenms user from cron:
#
#   sudo install -m 0755 -o root -g root /opt/librenms/vendor/xblossia/librenms-theme-selector/scripts/ensure-installed.sh \
#        /usr/local/sbin/theme-selector-ensure.sh
#   echo '17 * * * * librenms /usr/local/sbin/theme-selector-ensure.sh' | sudo tee /etc/cron.d/theme-selector-ensure
#
# (A copy keeps working when the package is gone, and goes stale only if this script changes.)
# When it acts, or fails, it says so on standard output and appends a line to
# logs/theme-selector-ensure.log under LibreNMS; it is silent when there is nothing to do.
# Exit status: 0 nothing to do or done, 1 it tried and failed, 2 it can't run here (root, wrong directory).
#
# LIBRENMS_DIR overrides /opt/librenms. The body is a function and the script exits on the line that
# calls it, so replacing this file while it runs is harmless.

PACKAGE=xblossia/librenms-theme-selector

main() {
    LIBRENMS_DIR=${LIBRENMS_DIR:-/opt/librenms}

    if [ "$(id -u)" = 0 ]; then
        echo "Run this as the librenms user, not root: it would leave root-owned files under $LIBRENMS_DIR." >&2
        return 2
    fi
    cd "$LIBRENMS_DIR" 2>/dev/null && [ -x ./lnms ] || {
        echo "$LIBRENMS_DIR is not a LibreNMS directory (set LIBRENMS_DIR)." >&2
        return 2
    }

    # Installed: nothing to do.
    if [ -f "vendor/$PACKAGE/composer.json" ]; then
        return 0
    fi

    # Listed for daily.sh? Only then was it supposed to be here.
    constraint=$(php daily.php -f composer_get_plugins 2>/dev/null | tr ' ' '\n' | sed -n "s|^$PACKAGE:||p" | head -n 1)
    if [ -z "$constraint" ]; then
        return 0
    fi

    # Never in the middle of an update (daily.sh, or Composer itself, would be racing this).
    if command -v pgrep >/dev/null 2>&1 && { pgrep -f 'daily\.sh' >/dev/null 2>&1 || pgrep -f '^([^ ]*/)?php .*composer' >/dev/null 2>&1; }; then
        return 0
    fi

    log() {
        echo "theme-selector-ensure: $*"
        if [ -d logs ] && [ -w logs ]; then
            echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $*" >>logs/theme-selector-ensure.log 2>/dev/null
        fi
    }

    log "$PACKAGE is listed in composer.plugins.json ($constraint) but not installed; running plugin:add"
    if ! ./lnms plugin:add "$PACKAGE" "$constraint" >/dev/null 2>&1; then
        log "plugin:add failed (the source is probably still unreachable); nothing changed, will try again at the next run"
        return 1
    fi
    ./lnms migrate --force >/dev/null 2>&1 || log "migrate failed: run ./lnms migrate --force"
    if ls bootstrap/cache/routes-*.php >/dev/null 2>&1; then
        php artisan route:cache >/dev/null 2>&1 || log "route:cache failed: run php artisan route:cache"
    fi
    ./lnms theme-selector:publish >/dev/null 2>&1 || log "theme-selector:publish failed: run ./lnms theme-selector:publish"
    log "restored $PACKAGE ($constraint)"

    return 0
}

main "$@"
exit $?
