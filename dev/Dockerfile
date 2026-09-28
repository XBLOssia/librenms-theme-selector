# The official image plus one init script that installs this repo as a
# package plugin and sets up passwordless dev login. See dev/README.md.
FROM librenms/librenms:26.9.1.1

COPY rootfs /
RUN chmod +x /etc/cont-init.d/09-theme-selector.sh
