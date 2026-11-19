# The official image plus one init script that installs this repo as a
# package plugin and sets up passwordless dev login. See dev/README.md.
# The LibreNMS release to build on; the nightly CI check builds on `latest` (LIBRENMS_VERSION=latest).
ARG LIBRENMS_VERSION=26.9.1.1
FROM librenms/librenms:${LIBRENMS_VERSION}

COPY rootfs /
# Older images use the legacy cont-init sequence. Newer images use native
# s6-rc services: the dev hook must run after LibreNMS config and migrations,
# and before nginx or PHP-FPM starts. Never install both hooks.
RUN chmod +x /usr/local/sbin/theme-selector-dev.sh && \
    if [ -d /etc/s6-overlay/s6-rc.d/config ]; then \
      mkdir -p /etc/s6-overlay/s6-rc.d/theme-selector-dev/dependencies.d \
        /etc/s6-overlay/s6-rc.d/user/contents.d \
        /etc/s6-overlay/s6-rc.d/nginx/dependencies.d \
        /etc/s6-overlay/s6-rc.d/php-fpm/dependencies.d && \
      printf 'oneshot\n' > /etc/s6-overlay/s6-rc.d/theme-selector-dev/type && \
      printf 'with-contenv\n/usr/local/sbin/theme-selector-dev.sh\n' > /etc/s6-overlay/s6-rc.d/theme-selector-dev/up && \
      touch /etc/s6-overlay/s6-rc.d/theme-selector-dev/dependencies.d/config \
        /etc/s6-overlay/s6-rc.d/nginx/dependencies.d/theme-selector-dev \
        /etc/s6-overlay/s6-rc.d/php-fpm/dependencies.d/theme-selector-dev \
        /etc/s6-overlay/s6-rc.d/user/contents.d/theme-selector-dev; \
    else \
      mkdir -p /etc/cont-init.d && \
      ln -s /usr/local/sbin/theme-selector-dev.sh /etc/cont-init.d/09-theme-selector.sh; \
    fi
