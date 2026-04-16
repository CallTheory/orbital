#!/bin/sh
set -e

# Bootstrap: if no managed cert exists yet (fresh install, acme.sh
# hasn't issued a cert), generate a temporary self-signed cert so
# nginx can start. The self-signed cert lets the admin access the
# dashboard over HTTPS (with a browser warning) to configure ACME
# and issue a real cert. Once acme.sh runs, the real cert overwrites
# these files on the shared volume and a deploy-hook reload makes
# nginx serve it.

CERT_DIR="/etc/nginx/certs"
CERT_FILE="${CERT_DIR}/fullchain.pem"
KEY_FILE="${CERT_DIR}/privkey.pem"

if [ ! -f "$CERT_FILE" ] || [ ! -s "$CERT_FILE" ]; then
    echo "[nginx-tls] No managed cert found — generating temporary self-signed cert..."
    mkdir -p "$CERT_DIR"
    openssl req -x509 -nodes -days 365 \
        -newkey rsa:2048 \
        -keyout "$KEY_FILE" \
        -out "$CERT_FILE" \
        -subj "/CN=orbital-self-signed/O=Orbital/OU=Dev" \
        2>/dev/null
    echo "[nginx-tls] Self-signed cert generated. Replace with a real cert via acme.sh."
fi

# Make cert files readable by other containers sharing the
# tls-certs volume (pgAdmin runs as UID 5050, not root).
# The private key is protected by container isolation — only
# containers that mount the volume can read it.
chmod 644 "$KEY_FILE" "$CERT_FILE" 2>/dev/null || true

exec nginx -g "daemon off;"
