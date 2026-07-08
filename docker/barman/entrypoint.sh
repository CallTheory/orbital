#!/usr/bin/env bash
#
# Barman repo entrypoint.
#
# 1. Persist sshd host keys across container rebuilds.
# 2. Generate the shared client key pair used by Patroni nodes for
#    archive_command (`barman-wal-archive`) and replica clone staging
#    (`barman-recover.sh`).
# 3. Render /etc/barman/barman.conf and /etc/barman.d/orbital.conf
#    from templates.
# 4. Render ~barman/.pgpass so libpq finds the cluster credentials.
# 5. Force a one-shot `barman cron` so the streaming slot is created
#    early (Patroni declares it as permanent, so this is a no-op once
#    Patroni has bootstrapped — but the first-boot ordering matters).
# 6. Start system cron (drives `barman cron` every minute) plus sshd
#    in the foreground.

set -euo pipefail

log() { echo "[barman-repo] $*" >&2; }

# --- Required env vars (with sensible dev defaults) ---
: "${BARMAN_SERVER_NAME:=orbital}"
: "${PATRONI_NODE_1:=patroni-1}"
: "${PATRONI_NODE_2:=patroni-2}"
: "${PATRONI_NODE_3:=patroni-3}"
: "${BARMAN_DB_PASSWORD:?BARMAN_DB_PASSWORD is required}"
: "${BARMAN_STREAMING_PASSWORD:?BARMAN_STREAMING_PASSWORD is required}"
# Phase B: cloud destinations. Primary defaults to the in-cluster
# SeaweedFS filer; offsite stays unset/inert unless explicitly
# configured.
: "${PRIMARY_S3_ENDPOINT:=http://seaweed-filer-1:8333}"
: "${PRIMARY_S3_BUCKET:=orbital-backups}"
: "${PRIMARY_S3_KEY:=orbital}"
: "${PRIMARY_S3_SECRET:=}"
: "${PRIMARY_S3_REGION:=us-east-1}"
: "${OFFSITE_S3_ENDPOINT:=}"
: "${OFFSITE_S3_BUCKET:=}"
: "${OFFSITE_S3_KEY:=}"
: "${OFFSITE_S3_SECRET:=}"
: "${OFFSITE_S3_REGION:=us-east-1}"
export BARMAN_SERVER_NAME PATRONI_NODE_1 PATRONI_NODE_2 PATRONI_NODE_3 \
       PRIMARY_S3_ENDPOINT PRIMARY_S3_BUCKET PRIMARY_S3_KEY PRIMARY_S3_SECRET PRIMARY_S3_REGION \
       OFFSITE_S3_ENDPOINT OFFSITE_S3_BUCKET OFFSITE_S3_KEY OFFSITE_S3_SECRET OFFSITE_S3_REGION

SSH_SHARED_DIR=/var/lib/barman-ssh
PRIV=${SSH_SHARED_DIR}/id_ed25519
PUB=${SSH_SHARED_DIR}/id_ed25519.pub

# --- sshd host keys (persisted in /etc/ssh/keys volume) ---
mkdir -p /etc/ssh/keys
if [ ! -f /etc/ssh/keys/ssh_host_ed25519_key ]; then
    log "generating sshd host keys into /etc/ssh/keys"
    ssh-keygen -t rsa -N '' -f /etc/ssh/keys/ssh_host_rsa_key
    ssh-keygen -t ecdsa -N '' -f /etc/ssh/keys/ssh_host_ecdsa_key
    ssh-keygen -t ed25519 -N '' -f /etc/ssh/keys/ssh_host_ed25519_key
fi
for k in ssh_host_rsa_key ssh_host_ecdsa_key ssh_host_ed25519_key; do
    ln -sf /etc/ssh/keys/"$k" /etc/ssh/"$k"
    ln -sf /etc/ssh/keys/"$k".pub /etc/ssh/"$k".pub
done

cp /etc/ssh/sshd_config.orbital /etc/ssh/sshd_config

# --- client key pair shared with Patroni nodes ---
if [ ! -f "${PRIV}" ]; then
    log "generating shared Patroni→barman ssh key pair at ${PRIV}"
    ssh-keygen -t ed25519 -N '' -C "patroni→barman" -f "${PRIV}"
    chown barman:barman "${PRIV}" "${PUB}"
    chmod 0600 "${PRIV}"
    chmod 0644 "${PUB}"
fi

# --- authorized_keys ---
mkdir -p /home/barman/.ssh
cp "${PUB}" /home/barman/.ssh/authorized_keys
chown -R barman:barman /home/barman/.ssh
chmod 0700 /home/barman/.ssh
chmod 0600 /home/barman/.ssh/authorized_keys

# --- barman config rendering ---
# Templates live at /usr/local/share/barman-orbital/ (outside both
# /etc/barman.d and /etc/barman); rendered output goes into the
# Barman config dirs.
log "rendering /etc/barman/barman.conf"
envsubst < /usr/local/share/barman-orbital/barman.conf.tmpl > /etc/barman/barman.conf
chown barman:barman /etc/barman/barman.conf
chmod 0640 /etc/barman/barman.conf

log "rendering /etc/barman.d/${BARMAN_SERVER_NAME}.conf"
envsubst < /usr/local/share/barman-orbital/orbital.conf.tmpl > /etc/barman.d/${BARMAN_SERVER_NAME}.conf
chown barman:barman /etc/barman.d/${BARMAN_SERVER_NAME}.conf
chmod 0640 /etc/barman.d/${BARMAN_SERVER_NAME}.conf

# --- pgpass for the barman user ---
# libpq auto-reads ~/.pgpass for any host listed in conninfo. We
# enumerate every Patroni node × every credential so failover doesn't
# require an entrypoint re-run.
PGPASS=/home/barman/.pgpass
{
    for node in "${PATRONI_NODE_1}" "${PATRONI_NODE_2}" "${PATRONI_NODE_3}"; do
        echo "${node}:5432:postgres:barman:${BARMAN_DB_PASSWORD}"
        echo "${node}:5432:replication:streaming_barman:${BARMAN_STREAMING_PASSWORD}"
    done
} > "${PGPASS}"
chown barman:barman "${PGPASS}"
chmod 0600 "${PGPASS}"

# --- Cloud destination wrapper scripts ---
# /etc/barman/cloud-wal-archive.sh — invoked by Barman as a
#   pre_archive_retry_script hook, runs on every WAL about to be
#   archived. Mirrors that one WAL file to PRIMARY_S3_* (SeaweedFS
#   by default) and, if configured, OFFSITE_S3_*. No-ops gracefully
#   when a destination's bucket/secret is unset; Barman retries the
#   hook on transient SeaweedFS unavailability.
#
# Full-backup-to-cloud is NOT done from this container.
# barman-cloud-backup reads pg_control directly from the local
# data directory, so it must run on a Patroni node — see
# /etc/patroni/cloud-backup-runner.sh on each Patroni container,
# which Phase C's schedule runner will dispatch to via SSH.
#
# barman-cloud-* commands read S3 creds from AWS_* env vars; the
# wrapper exports them per-invocation.

cat > /etc/barman/cloud-wal-archive.sh <<'OUTER_EOF'
#!/usr/bin/env bash
# Mirror a single WAL file to S3 destination(s). Invoked by Barman
# as post_archive_script — receives BARMAN_SERVER and BARMAN_FILE
# in the env (along with the PRIMARY_*/OFFSITE_* vars exported via
# /etc/default/barman).
set -eu

push_wal() {
    local endpoint="$1" bucket="$2" key="$3" secret="$4" region="$5"
    if [ -z "${bucket}" ] || [ -z "${secret}" ]; then
        return 0
    fi
    AWS_ACCESS_KEY_ID="${key}" \
    AWS_SECRET_ACCESS_KEY="${secret}" \
    AWS_DEFAULT_REGION="${region}" \
        barman-cloud-wal-archive \
            --endpoint-url "${endpoint}" \
            "s3://${bucket}" "${BARMAN_SERVER}" "${BARMAN_FILE}"
}

push_wal "${PRIMARY_S3_ENDPOINT}" "${PRIMARY_S3_BUCKET}" \
    "${PRIMARY_S3_KEY}" "${PRIMARY_S3_SECRET}" "${PRIMARY_S3_REGION}"
push_wal "${OFFSITE_S3_ENDPOINT}" "${OFFSITE_S3_BUCKET}" \
    "${OFFSITE_S3_KEY}" "${OFFSITE_S3_SECRET}" "${OFFSITE_S3_REGION}"
OUTER_EOF

chmod 0755 /etc/barman/cloud-wal-archive.sh
chown barman:barman /etc/barman/cloud-wal-archive.sh

# Hook scripts run from cron with whatever env Barman exports; the
# PRIMARY_*/OFFSITE_* vars need to survive that boundary. Debian's
# cron reads /etc/default/barman before running the barman command.
# Write the cloud env there so post_archive_script picks them up.
{
    echo "PRIMARY_S3_ENDPOINT='${PRIMARY_S3_ENDPOINT}'"
    echo "PRIMARY_S3_BUCKET='${PRIMARY_S3_BUCKET}'"
    echo "PRIMARY_S3_KEY='${PRIMARY_S3_KEY}'"
    echo "PRIMARY_S3_SECRET='${PRIMARY_S3_SECRET}'"
    echo "PRIMARY_S3_REGION='${PRIMARY_S3_REGION}'"
    echo "OFFSITE_S3_ENDPOINT='${OFFSITE_S3_ENDPOINT}'"
    echo "OFFSITE_S3_BUCKET='${OFFSITE_S3_BUCKET}'"
    echo "OFFSITE_S3_KEY='${OFFSITE_S3_KEY}'"
    echo "OFFSITE_S3_SECRET='${OFFSITE_S3_SECRET}'"
    echo "OFFSITE_S3_REGION='${OFFSITE_S3_REGION}'"
    echo "BARMAN_SERVER_NAME='${BARMAN_SERVER_NAME}'"
    echo "PATRONI_NODE_1='${PATRONI_NODE_1}'"
    echo "PATRONI_NODE_2='${PATRONI_NODE_2}'"
    echo "PATRONI_NODE_3='${PATRONI_NODE_3}'"
} > /etc/default/barman
chmod 0640 /etc/default/barman
chown barman:barman /etc/default/barman

# --- system cron entry: barman cron every minute ---
# `barman cron` is the supervisor: it spawns/restarts receive-wal,
# enforces retention, and updates the catalog. It MUST run as the
# barman OS user. The env file is sourced first so the cloud-mirror
# hook scripts see PRIMARY_*/OFFSITE_* env vars when they're spawned
# from inside barman cron.
cat > /etc/cron.d/barman <<EOF
* * * * * barman set -a && . /etc/default/barman && set +a && /usr/bin/barman cron >> /var/log/barman/cron.log 2>&1
EOF
chmod 0644 /etc/cron.d/barman

# Cron's logfile must exist with the right ownership before the
# first tick so the redirect doesn't fail closed.
mkdir -p /var/log/barman
touch /var/log/barman/cron.log
chown -R barman:barman /var/log/barman

# Try a one-shot `barman cron` immediately so the streaming slot
# initialization race is resolved on first boot. If Patroni isn't
# ready yet (slot doesn't exist), this fails harmlessly and the next
# cron tick picks it up.
log "running first-boot 'barman cron' (errors here are expected if Patroni is still bootstrapping)"
gosu barman bash -c 'set -a && . /etc/default/barman && set +a && barman cron' || true

log "starting cron + sshd"
service cron start
exec /usr/sbin/sshd -D -e
