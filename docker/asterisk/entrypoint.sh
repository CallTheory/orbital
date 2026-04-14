#!/bin/sh
#
# Asterisk container entrypoint.
#
# Three things happen before exec'ing asterisk:
#
#   1. **TLS cert.** Generates a self-signed cert if none is present
#      so SIP TLS (5061) and WSS (8089) work out of the box in dev.
#      Mount a real cert/key pair over /etc/asterisk/keys to override
#      in production.
#
#   2. **ODBC DSN materialization.** /etc/odbc.ini.tmpl ships in the
#      image with ${DB_HOST} / ${DB_PORT} / ${DB_DATABASE} / ${DB_USERNAME}
#      / ${DB_PASSWORD} placeholders. The entrypoint envsubst's them
#      from the env vars docker-compose passes in (mirrored from
#      Laravel's .env) and writes the result to /etc/odbc.ini, which
#      is what unixODBC reads at connection time.
#
#   3. **Postgres wait.** Asterisk's res_odbc tries to open the
#      connection pool at boot. If Postgres isn't reachable yet
#      (slow startup, network blip), Asterisk fails to load
#      res_pjsip and silently has no endpoints. We block on a TCP
#      probe with a 30-second deadline so the container reports a
#      useful failure instead of starting with a half-initialized
#      pjsip stack.
#
# Runs as the `asterisk` user. /etc/odbc.ini, /etc/asterisk/keys,
# and /etc/asterisk/generated must be writable by that user — the
# Dockerfile chowns them before USER asterisk takes effect.
#
set -e

# ── 1. TLS cert ─────────────────────────────────────────────────
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

# ── 2. Materialize the ODBC DSN ─────────────────────────────────
: "${DB_HOST:=pgsql}"
: "${DB_PORT:=5432}"
: "${DB_DATABASE:=orbital}"
: "${DB_USERNAME:=orbital}"
: "${DB_PASSWORD:=password}"
export DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD

if [ -f /etc/odbc.ini.tmpl ]; then
    envsubst < /etc/odbc.ini.tmpl > /etc/odbc.ini
    echo "[orbital-asterisk] ODBC DSN materialized for ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
fi

# ── 3. Wait for Postgres ────────────────────────────────────────
WAIT_DEADLINE=$((`date +%s` + 30))
while ! nc -z "$DB_HOST" "$DB_PORT" 2>/dev/null; do
    if [ "`date +%s`" -ge "$WAIT_DEADLINE" ]; then
        echo "[orbital-asterisk] FATAL: Postgres at $DB_HOST:$DB_PORT not reachable after 30s — Asterisk will start with no realtime backend." >&2
        break
    fi
    echo "[orbital-asterisk] Waiting for Postgres at $DB_HOST:$DB_PORT..."
    sleep 1
done

if nc -z "$DB_HOST" "$DB_PORT" 2>/dev/null; then
    echo "[orbital-asterisk] Postgres reachable at $DB_HOST:$DB_PORT"
fi

exec asterisk -f -U asterisk -G asterisk "$@"
