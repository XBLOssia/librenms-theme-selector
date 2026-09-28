#!/usr/bin/with-contenv bash
# shellcheck shell=bash
#
# Dev-only setup, run by s6 on every container start, after the image's own
# init (01-08) has written nginx/php config and migrated the database.
#
#   1. Header-based login, so nobody types a password into the dev instance.
#   2. PHP edits in /plugin take effect on the next request.
#   3. Install /plugin (this repo, bind-mounted) as a package plugin.
#   4. Serve the bundled skins where the plugin expects them.
#   5. Seed one admin and one non-admin user.
set -e

LIBRENMS_PATH=${LIBRENMS_PATH:-/opt/librenms}
PACKAGE=xblossia/librenms-theme-selector
cd "$LIBRENMS_PATH"

as_librenms() { gosu librenms:librenms env COMPOSER_HOME=/data/composer "$@"; }

echo "[theme-selector] dev auth"
# http-auth takes the username from $_SERVER[http_auth_header]. nginx fills
# DEV_USER from the X-Dev-User request header, defaulting to dev-admin, so the
# browser is dev-admin and `curl -H 'X-Dev-User: dev-user'` is the non-admin.
cat >/data/config/dev-auth.php <<'EOF'
<?php
$config['auth_mechanism'] = 'http-auth';
$config['http_auth_header'] = 'DEV_USER';
EOF
chown librenms:librenms /data/config/dev-auth.php

# nginx.conf is regenerated from a template by 03-config.sh on every start,
# so these edits are re-applied each time rather than accumulating.
sed -i 's|^http {|http {\n    map $http_x_dev_user $dev_user { "" dev-admin; default $http_x_dev_user; }|' /etc/nginx/nginx.conf
sed -i 's|include fastcgi_params;|include fastcgi_params;\n            fastcgi_param DEV_USER $dev_user;|' /etc/nginx/nginx.conf

echo "[theme-selector] opcache: revalidate every request"
sed -i 's|^opcache.revalidate_freq=.*|opcache.revalidate_freq=0|' /etc/php84/conf.d/opcache.ini

# The image enables the first-run wizard on an empty database; the users are
# seeded below instead.
sed -i '/^INSTALL=/d' .env

echo "[theme-selector] plugin"
mkdir -p /data/composer
chown librenms:librenms /data/composer
as_librenms composer config --global repositories.theme-selector \
  '{"type": "path", "url": "/plugin", "options": {"symlink": true}}'
# vendor/ lives in the image layer, so a recreated container starts without
# the plugin; a restarted one still has it.
if [ ! -e "vendor/$PACKAGE" ]; then
  as_librenms php lnms plugin:add "$PACKAGE" '@dev'
fi

echo "[theme-selector] skins"
# The served layout SkinRepository expects, linked straight to the repo.
# (Earlier versions linked the whole directory to /plugin/skins.)
[ -L html/css/custom/theme-selector ] && rm html/css/custom/theme-selector
mkdir -p html/css/custom/theme-selector
ln -sfn /plugin/base/base.css html/css/custom/theme-selector/base.css
ln -sfn /plugin/skins html/css/custom/theme-selector/skins

echo "[theme-selector] users"
db() { mariadb -h "$DB_HOST" -u "$DB_USER" "-p$DB_PASSWORD" "$DB_NAME" -N -e "$1"; }
for spec in dev-admin:admin dev-user:user; do
  name=${spec%%:*}
  role=${spec##*:}
  # Only on first start: a second user:add would add a duplicate row.
  if [ "$(db "SELECT COUNT(*) FROM users WHERE username='$name'")" = 0 ]; then
    # Random password nobody uses: http-auth never checks it.
    as_librenms php lnms user:add --no-interaction -r "$role" \
      -p "$(head -c 24 /dev/urandom | base64)" "$name" >/dev/null
    # user:add records auth_type=mysql; http-auth only finds its own type.
    db "UPDATE users SET auth_type='http-auth' WHERE username='$name'"
  fi
done

artisan config:cache --no-interaction
artisan route:clear --no-interaction
artisan view:clear --no-interaction
