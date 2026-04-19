#!/usr/bin/env bash
#
# HAProxy entrypoint — renders haproxy.cfg.tmpl via envsubst so
# passwords and per-cluster knobs flow in through the environment
# instead of being baked into the image.

set -euo pipefail

: "${REDIS_PASSWORD:?REDIS_PASSWORD is required for Valkey tcp-check AUTH}"
export REDIS_PASSWORD

# envsubst only expands ${REDIS_PASSWORD}; everything else in the
# template is left intact. Limit the variable list to avoid
# accidentally mangling haproxy's own ${var} syntax (we don't use
# any in this config, but belt + suspenders).
envsubst '${REDIS_PASSWORD}' \
    < /etc/haproxy/haproxy.cfg.tmpl \
    > /etc/haproxy/haproxy.cfg

# Validate before handing off; a config error here is much easier
# to debug at startup than via failed health checks later.
haproxy -c -f /etc/haproxy/haproxy.cfg

exec haproxy -f /etc/haproxy/haproxy.cfg -W -db
