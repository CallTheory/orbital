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

# ── AMI secret ─────────────────────────────────────────────────
# manager.conf ships a placeholder secret; the real one comes from
# ASTERISK_AMI_SECRET, shared with Laravel and the config-sync sidecar,
# which log in to AMI to trigger `dialplan reload`. Without this, AMI
# auth fails and generated config never gets applied automatically.
if [ -n "${ASTERISK_AMI_SECRET:-}" ]; then
    sed -i "s|^secret = .*|secret = ${ASTERISK_AMI_SECRET}|" /etc/asterisk/manager.conf
    echo "[orbital-asterisk] AMI secret set from ASTERISK_AMI_SECRET"
fi

# ── systemname ─────────────────────────────────────────────────
# Asterisk writes ps_contacts.reg_server from this value on every
# dynamic registration. With two Asterisks sharing the ARA tables,
# reg_server is the ONLY per-node signal that tells us which
# Asterisk holds the actual WSS/SIP socket for a given softphone
# contact. We prefer an explicit ASTERISK_NODE_NAME env (set per
# service in compose) and fall back to HOSTNAME.
NODE_NAME="${ASTERISK_NODE_NAME:-${HOSTNAME:-asterisk}}"
if grep -q '^;systemname = my_system_name' /etc/asterisk/asterisk.conf 2>/dev/null; then
    sed -i "s/^;systemname = my_system_name.*/systemname = ${NODE_NAME}/" \
        /etc/asterisk/asterisk.conf
    echo "[orbital-asterisk] systemname = ${NODE_NAME}"
fi

# ── Edge / NAT media + signaling address ───────────────────────
# When Asterisk sits behind a NodePort/LoadBalancer (e.g. the local
# k3d edge test where an off-cluster rtpengine relays RTP in), the
# pod IP it would otherwise advertise in SDP/Contact is unreachable
# from the edge. ASTERISK_EXTERNAL_ADDRESS makes pjsip advertise a
# reachable address instead, and ASTERISK_RTP_START/END pin the RTP
# window to the range that's NodePort-exposed. All optional — unset
# in a normal in-cluster deploy where rtpengine lives at the VM edge.
if [ -n "${ASTERISK_EXTERNAL_ADDRESS:-}" ]; then
    sed -i "/^\[transport-\(udp\|tcp\)\]/a external_media_address = ${ASTERISK_EXTERNAL_ADDRESS}\nexternal_signaling_address = ${ASTERISK_EXTERNAL_ADDRESS}" \
        /etc/asterisk/pjsip.conf
    echo "[orbital-asterisk] external address = ${ASTERISK_EXTERNAL_ADDRESS}"
fi
# ── Node-network mode (Kubernetes hostNetwork) ─────────────────
# On Kubernetes, Asterisk shares its node's network so the SIP edge VMs
# can reach it, and ASTERISK_BIND_ADDRESS is the node's private IP.
# Binding SIP and HTTP/WSS to it keeps them off the node's public
# interface and makes pjsip advertise that address in Contact/SDP —
# reachable from the edge VMs and from pods alike. AMI stays on all
# addresses (the config-sync sidecar uses 127.0.0.1) but only private
# sources may log in. RTP binds wide; the node firewall covers it.
if [ -n "${ASTERISK_BIND_ADDRESS:-}" ]; then
    sed -i "s/^bind = 0\.0\.0\.0:/bind = ${ASTERISK_BIND_ADDRESS}:/" /etc/asterisk/pjsip.conf
    sed -i -e "s/^bindaddr = 0\.0\.0\.0/bindaddr = ${ASTERISK_BIND_ADDRESS}/" \
           -e "s/^tlsbindaddr = 0\.0\.0\.0:/tlsbindaddr = ${ASTERISK_BIND_ADDRESS}:/" \
        /etc/asterisk/http.conf
    sed -i "s|^permit = 0\.0\.0\.0/0\.0\.0\.0|permit = 127.0.0.0/255.0.0.0\npermit = 10.0.0.0/255.0.0.0\npermit = 172.16.0.0/255.240.0.0\npermit = 192.168.0.0/255.255.0.0|" \
        /etc/asterisk/manager.conf
    echo "[orbital-asterisk] SIP/HTTP bound to ${ASTERISK_BIND_ADDRESS}; AMI limited to private sources"
fi
if [ -n "${ASTERISK_RTP_START:-}" ] && [ -n "${ASTERISK_RTP_END:-}" ]; then
    sed -i -e "s/^rtpstart = .*/rtpstart = ${ASTERISK_RTP_START}/" \
           -e "s/^rtpend = .*/rtpend = ${ASTERISK_RTP_END}/" \
        /etc/asterisk/rtp.conf
    echo "[orbital-asterisk] rtp range = ${ASTERISK_RTP_START}-${ASTERISK_RTP_END}"
fi

# ── msmtp config for voicemail-by-email ────────────────────────
# Asterisk's voicemail app invokes /usr/sbin/sendmail (msmtp-mta
# symlink) when a voicemail arrives for a mailbox with email= set.
# Render /etc/msmtprc from MAIL_* env vars mirrored from Laravel's
# mail config.
: "${MAIL_HOST:=mailpit}"
: "${MAIL_PORT:=1025}"
: "${MAIL_USERNAME:=}"
: "${MAIL_PASSWORD:=}"
: "${MAIL_ENCRYPTION:=}"
: "${MAIL_FROM_ADDRESS:=voicemail@orbital.local}"

# msmtp's AUTH toggle + credentials are conditional. If MAIL_USERNAME
# is empty or the literal "null" (Laravel convention) we disable
# auth entirely; otherwise we emit user/password lines.
if [ -n "${MAIL_USERNAME}" ] && [ "${MAIL_USERNAME}" != "null" ]; then
    export MSMTP_AUTH_ENABLED="on"
    export MSMTP_AUTH_LINES="user           ${MAIL_USERNAME}
password       ${MAIL_PASSWORD}"
else
    export MSMTP_AUTH_ENABLED="off"
    export MSMTP_AUTH_LINES=""
fi

# TLS/STARTTLS match Laravel's MAIL_ENCRYPTION semantics: "tls" is
# STARTTLS on the submission port; "ssl" is implicit TLS.
case "${MAIL_ENCRYPTION}" in
    tls)  export MSMTP_TLS_ENABLED="on"; export MSMTP_STARTTLS_ENABLED="on";;
    ssl)  export MSMTP_TLS_ENABLED="on"; export MSMTP_STARTTLS_ENABLED="off";;
    *)    export MSMTP_TLS_ENABLED="off"; export MSMTP_STARTTLS_ENABLED="off";;
esac

export MAIL_HOST MAIL_PORT MAIL_FROM_ADDRESS

if [ -f /etc/msmtprc.tmpl ]; then
    envsubst < /etc/msmtprc.tmpl > /etc/msmtprc
    chmod 600 /etc/msmtprc
    echo "[orbital-asterisk] msmtp relay → ${MAIL_HOST}:${MAIL_PORT}"
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
