#!/usr/bin/env sh
#
# SeaweedFS filer entrypoint — renders filer.toml via envsubst so
# passwords from the environment get baked into the config before
# `weed filer` starts reading it.

set -eu

: "${REDIS_PASSWORD:?REDIS_PASSWORD is required}"
: "${SENTINEL_PASSWORD:?SENTINEL_PASSWORD is required}"
export REDIS_PASSWORD SENTINEL_PASSWORD

mkdir -p /etc/seaweedfs
envsubst < /etc/seaweedfs/filer.toml.tmpl > /etc/seaweedfs/filer.toml

# Hand off to weed with whatever args compose passed.
exec weed "$@"
