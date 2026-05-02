#!/usr/bin/env bash
set -euo pipefail

# fpm runs as `sail` against a Unix socket in /run/php — recreate on
# each container start because /run is tmpfs.
mkdir -p /run/php /run/nginx
chown sail:sail /run/php

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
