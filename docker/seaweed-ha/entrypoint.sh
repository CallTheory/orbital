#!/usr/bin/env bash
#
# SeaweedFS HA entrypoint.
#
# For `weed filer` the entrypoint first renders filer.toml and
# s3.json from their templates (interpolating passwords and other
# env vars). For `weed master` and `weed volume` the templating
# is skipped — those processes don't read toml configs and only
# need the arguments compose passed through.

set -euo pipefail

log() { echo "[seaweed-ha] $*" >&2; }

# Some reasonable defaults so missing env vars don't crash
# non-filer roles.
: "${REDIS_PASSWORD:=}"
: "${SENTINEL_PASSWORD:=}"
: "${AWS_SECRET_ACCESS_KEY:=}"
export REDIS_PASSWORD SENTINEL_PASSWORD AWS_SECRET_ACCESS_KEY

if [ "${1:-}" = "filer" ]; then
    if [ -z "${REDIS_PASSWORD}" ] || [ -z "${SENTINEL_PASSWORD}" ]; then
        echo "filer needs REDIS_PASSWORD and SENTINEL_PASSWORD to render filer.toml" >&2
        exit 1
    fi
    log "rendering /etc/seaweedfs/filer.toml (redis_sentinel backend)"
    envsubst < /etc/seaweedfs/filer.toml.tmpl > /etc/seaweedfs/filer.toml

    if [ -n "${AWS_SECRET_ACCESS_KEY}" ]; then
        log "rendering /etc/seaweedfs/s3.json"
        envsubst < /etc/seaweedfs/s3.json.tmpl > /etc/seaweedfs/s3.json
    fi
fi

log "exec weed $*"
exec weed "$@"
