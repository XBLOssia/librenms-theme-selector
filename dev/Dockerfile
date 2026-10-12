# The official image plus one init script that installs this repo as a
# package plugin and sets up passwordless dev login. See dev/README.md.
# The LibreNMS release to build on; the nightly CI check builds on `latest` (LIBRENMS_VERSION=latest).
ARG LIBRENMS_VERSION=26.9.1.1
FROM librenms/librenms:${LIBRENMS_VERSION}

COPY rootfs /
RUN chmod +x /etc/cont-init.d/09-theme-selector.sh
