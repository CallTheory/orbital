#!/usr/bin/env bash
set -euo pipefail

# fpm runs as `sail` against a Unix socket in /run/php. The dirs are
# created and chowned to sail at build time; recreate defensively in
# case /run is a tmpfs mount. Only chown when we're root — under the
# K8s pod securityContext this runs as non-root (uid 1000), where the
# build-time ownership already applies and a chown would (fatally) fail.
mkdir -p /run/php /run/nginx
if [ "$(id -u)" = "0" ]; then
    chown sail:sail /run/php /run/nginx
fi

# storage/ + bootstrap/cache/ ownership and perms are already correct
# in the image. K8s pod securityContext fsGroup=1000 handles PVC
# overlays; no runtime chown needed.

if [ "$#" -gt 0 ]; then
    # Horizon / Reverb pods pass `php artisan ...` via the K8s
    # `command:` override — bypass supervisord and run as `sail`.
    # Alpine ships su-exec (BusyBox's gosu equivalent), not gosu.
    exec su-exec sail "$@"
fi

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
