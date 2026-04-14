#!/bin/sh
#
# Asterisk container entrypoint.
#
# Generates a self-signed TLS certificate on first boot if none is
# present so SIP TLS (5061) and WSS (8089) are functional out of the
# box in dev and fresh installs. For production, mount a real cert/key
# pair into /etc/asterisk/keys before starting the container — the
# script leaves existing files alone.
#
# Runs as the `asterisk` user (Dockerfile USER directive), so
# /etc/asterisk/keys must be writable by that user. The Dockerfile
# creates it and chowns /etc/asterisk before switching users.
#
set -e

KEYS_DIR=/etc/asterisk/keys
CERT="$KEYS_DIR/asterisk.pem"
KEY="$KEYS_DIR/asterisk.key"
CN="${ASTERISK_TLS_CN:-${SIP_DOMAIN:-orbital.local}}"

if [ ! -f "$CERT" ] || [ ! -f "$KEY" ]; then
    echo "[orbital-asterisk] No TLS cert found at $CERT — generating self-signed (CN=$CN)"
    mkdir -p "$KEYS_DIR"
    openssl req -new -x509 -days 3650 -nodes \
        -subj "/C=US/ST=Local/L=Local/O=Orbital/CN=$CN" \
        -keyout "$KEY" \
        -out "$CERT" \
        >/dev/null 2>&1
    chmod 600 "$KEY"
    chmod 644 "$CERT"
    echo "[orbital-asterisk] Self-signed TLS cert generated — valid 10 years"
fi

exec asterisk -f -U asterisk -G asterisk "$@"
