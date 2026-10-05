#!/bin/sh
# Update Theme Selector now, or put it back if it has gone, and check the result.
#
# LibreNMS's daily.sh already updates every plugin listed in composer.plugins.json on each run;
# this does the same for this one plugin on demand, then the steps an update can need that
# daily.sh does not do for you (see docs/DEPLOYMENT.md, "Updates"). It is safe to run any time,
# and again: nothing changes if the plugin is already current.
#
#   sudo -u librenms /opt/librenms/vendor/xblossia/librenms-theme-selector/scripts/update.sh
#   sudo -u librenms .../scripts/update.sh ^1.0      # also change what it follows
#
# What it runs, in order:
#   ./lnms plugin:add xblossia/librenms-theme-selector <constraint>   composer require + composer.plugins.json
#   ./lnms migrate --force                                            the plugin's tables
#   php artisan route:cache                                           only if LibreNMS has cached its routes
#   ./lnms theme-selector:publish                                     copy the stylesheets into the webroot
#   ./lnms theme-selector:status                                      and check it all
#
# Run it as the librenms user, never as root: the files it writes under /opt/librenms must be
# owned by the user the web server runs as. LIBRENMS_DIR overrides the location.
#
# The body is a function and the script exits on the line that calls it: Composer replaces this
# very file during the update, and the shell must not read the new one halfway through.

PACKAGE=xblossia/librenms-theme-selector

main() {
    LIBRENMS_DIR=${LIBRENMS_DIR:-/opt/librenms}

    if [ "$(id -u)" = 0 ]; then
        echo "Run this as the librenms user, not root: sudo -u librenms $0" >&2
        return 2
    fi
    cd "$LIBRENMS_DIR" 2>/dev/null || {
        echo "Cannot enter $LIBRENMS_DIR (set LIBRENMS_DIR if LibreNMS lives elsewhere)." >&2
        return 2
    }
    [ -x ./lnms ] || {
        echo "$LIBRENMS_DIR/lnms not found: that is not a LibreNMS directory." >&2
        return 2
    }

    # Keep following whatever it follows now (a branch, a release range), else the branch.
    constraint=${1:-}
    if [ -z "$constraint" ]; then
        constraint=$(php daily.php -f composer_get_plugins 2>/dev/null | tr ' ' '\n' | sed -n "s|^$PACKAGE:||p" | head -n 1)
    fi
    constraint=${constraint:-dev-main}

    step() {
        echo "==> $*"
        "$@"
    }

    if ! step ./lnms plugin:add "$PACKAGE" "$constraint"; then
        echo >&2
        echo "plugin:add failed, and LibreNMS has put its composer files back as they were." >&2
        echo "If the message was 'Could not authenticate against github.com', switch the repository to" >&2
        echo "Composer's git mode (no GitHub API calls): see docs/DEPLOYMENT.md, \"Install\"." >&2
        return 1
    fi
    step ./lnms migrate --force || return 1

    # LibreNMS rebuilds this cache when it installs packages, and clears it before an update; a host
    # that built one by hand (the install steps do) needs it rebuilt for a new route to exist.
    if ls bootstrap/cache/routes-*.php >/dev/null 2>&1; then
        step php artisan route:cache || return 1
    fi

    step ./lnms theme-selector:publish || return 1
    echo
    ./lnms theme-selector:status
}

main "$@"
exit $?
