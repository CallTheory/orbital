#!/usr/bin/env bash
#
# Valkey HA image entrypoint. The SERVICE env var selects mode:
#   SERVICE=valkey    → render valkey.conf and exec valkey-server
#   SERVICE=sentinel  → render sentinel.conf and exec valkey-sentinel
#
# Per-node vars:
#   VALKEY_NODE_NAME          — hostname of this node (valkey-1/2/3)
#   SENTINEL_NODE_NAME        — hostname of this sentinel (sentinel-1/2/3)
#   VALKEY_INITIAL_PRIMARY    — name of the initial primary node (valkey-1)
#   REDIS_PASSWORD            — valkey auth password
#   SENTINEL_PASSWORD         — sentinel-to-sentinel auth password

set -euo pipefail

log() { echo "[valkey-ha] $*" >&2; }

: "${SERVICE:?SERVICE must be set to 'valkey' or 'sentinel'}"
: "${REDIS_PASSWORD:?REDIS_PASSWORD is required}"

case "${SERVICE}" in
  valkey)
    : "${VALKEY_NODE_NAME:?VALKEY_NODE_NAME is required}"
    : "${VALKEY_INITIAL_PRIMARY:?VALKEY_INITIAL_PRIMARY is required}"

    # First-boot role: is this the initial primary, or a replica of
    # the initial primary? After Sentinel has rewritten state, the
    # persistent AOF/RDB + Sentinel's runtime commands take over —
    # this directive only matters on an empty-data first boot.
    if [ "${VALKEY_NODE_NAME}" = "${VALKEY_INITIAL_PRIMARY}" ]; then
        REPLICA_OF_DIRECTIVE="# initial primary — no replicaof"
    else
        REPLICA_OF_DIRECTIVE="replicaof ${VALKEY_INITIAL_PRIMARY} 6379"
    fi
    export REPLICA_OF_DIRECTIVE VALKEY_NODE_NAME REDIS_PASSWORD

    log "rendering /etc/valkey/valkey.conf for ${VALKEY_NODE_NAME}"
    envsubst < /etc/valkey/valkey.conf.tmpl > /etc/valkey/valkey.conf
    chown valkey:valkey /etc/valkey/valkey.conf
    chmod 0640 /etc/valkey/valkey.conf

    log "starting valkey-server as ${VALKEY_NODE_NAME}"
    chown -R valkey:valkey /var/lib/valkey
    exec su-exec valkey valkey-server /etc/valkey/valkey.conf
    ;;

  sentinel)
    : "${SENTINEL_NODE_NAME:?SENTINEL_NODE_NAME is required}"
    : "${VALKEY_INITIAL_PRIMARY:?VALKEY_INITIAL_PRIMARY is required}"
    : "${SENTINEL_PASSWORD:?SENTINEL_PASSWORD is required}"

    export SENTINEL_NODE_NAME VALKEY_INITIAL_PRIMARY \
           REDIS_PASSWORD SENTINEL_PASSWORD

    # Sentinel rewrites its own config at runtime. If a rewritten
    # file already exists in the persistent dir, skip the template
    # render so we don't clobber recorded state.
    SENTINEL_CONF=/var/lib/sentinel/sentinel.conf
    chown -R valkey:valkey /var/lib/sentinel
    if [ ! -s "${SENTINEL_CONF}" ]; then
        log "rendering ${SENTINEL_CONF} for ${SENTINEL_NODE_NAME}"
        envsubst < /etc/valkey/sentinel.conf.tmpl > "${SENTINEL_CONF}"
        chown valkey:valkey "${SENTINEL_CONF}"
        chmod 0640 "${SENTINEL_CONF}"
    else
        log "reusing existing ${SENTINEL_CONF} (Sentinel-managed state)"
    fi

    log "starting valkey-sentinel as ${SENTINEL_NODE_NAME}"
    exec su-exec valkey valkey-sentinel "${SENTINEL_CONF}"
    ;;

  *)
    echo "unknown SERVICE='${SERVICE}' (expected 'valkey' or 'sentinel')" >&2
    exit 1
    ;;
esac
