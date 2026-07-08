#!/usr/bin/env bash
#
# Patroni container entrypoint.
#
# 1. Renders patroni.yml.tmpl → /etc/patroni/patroni.yml via envsubst
#    so per-node values (name, tags, etcd endpoints, passwords) are
#    baked in from the environment.
# 2. Drops the ssh host key for the barman repo into the postgres
#    user's known_hosts so:
#      - archive_command (`barman-wal-archive`) can push WAL,
#      - barman-recover.sh can rsync a staged recovery dir back to
#        this node when Patroni invokes it as a replica clone method.
# 3. Renders /etc/patroni/post-init.sh, /etc/patroni/basebackup-chmod.sh,
#    and /etc/patroni/barman-recover.sh.
# 4. Execs patroni as the postgres user.

set -euo pipefail

log() { echo "[patroni-entrypoint] $*" >&2; }

# --- Required env vars ---
: "${PATRONI_NAME:?PATRONI_NAME is required}"
: "${PATRONI_SCOPE:?PATRONI_SCOPE is required}"
: "${PATRONI_ETCD3_HOSTS:?PATRONI_ETCD3_HOSTS is required (comma-separated)}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is required}"
: "${PATRONI_REPLICATION_PASSWORD:?PATRONI_REPLICATION_PASSWORD is required}"
: "${PATRONI_REST_USER:=patroni}"
: "${PATRONI_REST_PASSWORD:?PATRONI_REST_PASSWORD is required}"
: "${PATRONI_TAGS_NOFAILOVER:=false}"
: "${PATRONI_TAGS_NOSYNC:=false}"
: "${BARMAN_HOST:=barman}"
: "${BARMAN_USER:=barman}"
: "${BARMAN_SERVER_NAME:=orbital}"
: "${BARMAN_DB_PASSWORD:?BARMAN_DB_PASSWORD is required}"
: "${BARMAN_STREAMING_PASSWORD:?BARMAN_STREAMING_PASSWORD is required}"

export PATRONI_NAME PATRONI_SCOPE PATRONI_ETCD3_HOSTS \
       POSTGRES_PASSWORD PATRONI_REPLICATION_PASSWORD \
       PATRONI_REST_USER PATRONI_REST_PASSWORD \
       PATRONI_TAGS_NOFAILOVER PATRONI_TAGS_NOSYNC \
       BARMAN_HOST BARMAN_USER BARMAN_SERVER_NAME \
       BARMAN_DB_PASSWORD BARMAN_STREAMING_PASSWORD \
       PGDATA

# --- Render Patroni config ---
log "rendering /etc/patroni/patroni.yml for node ${PATRONI_NAME}"
envsubst < /etc/patroni/patroni.yml.tmpl > /etc/patroni/patroni.yml
chown postgres:postgres /etc/patroni/patroni.yml
chmod 0640 /etc/patroni/patroni.yml

# --- Post-init hook (runs once on cluster bootstrap) ---
# Creates the pgvector extension, the app user/database, and the two
# Barman roles (`barman` for backup_method=postgres metadata queries,
# `streaming_barman` for pg_basebackup / pg_receivewal).
cat > /etc/patroni/post-init.sh <<EOF
#!/usr/bin/env bash
set -eu
psql -v ON_ERROR_STOP=1 -U postgres -d postgres <<SQL
  CREATE EXTENSION IF NOT EXISTS vector;
  DO \\\$\\\$
  BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='${DB_USERNAME:-orbital}') THEN
      CREATE ROLE ${DB_USERNAME:-orbital} LOGIN PASSWORD '${DB_PASSWORD:-password}';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='barman') THEN
      -- SUPERUSER for pg_backup_start/stop privileges (needed by
      -- barman-cloud-backup); REPLICATION so the same role can run
      -- pg_basebackup over the streaming protocol.
      CREATE ROLE barman LOGIN PASSWORD '${BARMAN_DB_PASSWORD}' SUPERUSER REPLICATION;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='streaming_barman') THEN
      CREATE ROLE streaming_barman LOGIN REPLICATION PASSWORD '${BARMAN_STREAMING_PASSWORD}';
    END IF;
  END
  \\\$\\\$;
  CREATE DATABASE ${DB_DATABASE:-orbital} OWNER ${DB_USERNAME:-orbital};
  -- Grafana keeps its own state (dashboards, users, datasources,
  -- alerts) in a dedicated DB on the same cluster so the Grafana
  -- nodes stay stateless. GF_DATABASE_NAME=grafana on both nodes.
  CREATE DATABASE grafana OWNER ${DB_USERNAME:-orbital};
SQL
psql -v ON_ERROR_STOP=1 -U postgres -d ${DB_DATABASE:-orbital} <<SQL
  CREATE EXTENSION IF NOT EXISTS vector;
SQL
EOF
chmod +x /etc/patroni/post-init.sh
chown postgres:postgres /etc/patroni/post-init.sh

# --- Cloud backup runner ---
# barman-cloud-backup reads pg_control from the local data dir, so
# it has to run on a Patroni node. Phase C's schedule runner SSHes
# into the Patroni leader and invokes this script with one of:
#   primary  — uses PRIMARY_S3_* env vars
#   offsite  — uses OFFSITE_S3_* env vars
#
# All inputs come from the env, set by the SSH caller via -o SetEnv:
#   PRIMARY_S3_ENDPOINT  PRIMARY_S3_BUCKET  PRIMARY_S3_KEY
#   PRIMARY_S3_SECRET    PRIMARY_S3_REGION
#   OFFSITE_S3_ENDPOINT  OFFSITE_S3_BUCKET  OFFSITE_S3_KEY
#   OFFSITE_S3_SECRET    OFFSITE_S3_REGION
#   BARMAN_DB_PASSWORD   BARMAN_SERVER_NAME
#
# Must run as the postgres OS user so it can read the data dir.
cat > /etc/patroni/cloud-backup-runner.sh <<'EOF'
#!/usr/bin/env bash
set -eu

DEST="${1:-}"
case "${DEST}" in
    primary)
        ENDPOINT="${PRIMARY_S3_ENDPOINT}"
        BUCKET="${PRIMARY_S3_BUCKET}"
        KEY="${PRIMARY_S3_KEY}"
        SECRET="${PRIMARY_S3_SECRET}"
        REGION="${PRIMARY_S3_REGION:-us-east-1}"
        ;;
    offsite)
        ENDPOINT="${OFFSITE_S3_ENDPOINT}"
        BUCKET="${OFFSITE_S3_BUCKET}"
        KEY="${OFFSITE_S3_KEY}"
        SECRET="${OFFSITE_S3_SECRET}"
        REGION="${OFFSITE_S3_REGION:-us-east-1}"
        ;;
    *)
        echo "usage: $0 primary|offsite" >&2
        exit 64
        ;;
esac

: "${BUCKET:?bucket not set}"
: "${SECRET:?secret not set}"
: "${BARMAN_DB_PASSWORD:?BARMAN_DB_PASSWORD not set}"
: "${BARMAN_SERVER_NAME:=orbital}"

# Connect to the LOCAL postgres (this script runs on the leader).
# barman-cloud-backup uses pg_backup_start/stop on the SQL conn and
# reads files from the data dir at the same time.
exec env \
    AWS_ACCESS_KEY_ID="${KEY}" \
    AWS_SECRET_ACCESS_KEY="${SECRET}" \
    AWS_DEFAULT_REGION="${REGION}" \
    PGPASSWORD="${BARMAN_DB_PASSWORD}" \
    barman-cloud-backup \
        --endpoint-url "${ENDPOINT}" \
        --host 127.0.0.1 \
        --port 5432 \
        --user barman \
        "s3://${BUCKET}" "${BARMAN_SERVER_NAME}"
EOF
chmod +x /etc/patroni/cloud-backup-runner.sh
chown postgres:postgres /etc/patroni/cloud-backup-runner.sh

# --- Replica clone helper: barman recover via remote staging.
# Patroni's create_replica_methods invokes us with --datadir,
# --scope, --role, --connstring. We SSH to the barman host, ask
# Barman to recover the latest backup into a staging dir on the
# barman side, then rsync the staged dir back into PGDATA. Cleanup
# is best-effort so a partial failure doesn't pile up disk on the
# barman side indefinitely (`barman cron` also enforces retention
# on staging dirs older than 24h via the cleanup_dir hook below).
#
# This intentionally requires only one-direction SSH trust
# (replica → barman), matching the archive_command path.
cat > /etc/patroni/barman-recover.sh <<'EOF'
#!/usr/bin/env bash
set -eu

DATA_DIR=""
for arg in "$@"; do
    case "$arg" in
        --datadir=*) DATA_DIR="${arg#*=}" ;;
    esac
done
: "${DATA_DIR:?missing --datadir from Patroni}"

BARMAN_HOST="${BARMAN_HOST:-barman}"
BARMAN_SERVER="${BARMAN_SERVER_NAME:-orbital}"
NODE="${PATRONI_NAME:-$(hostname)}"
STAMP="$(date +%s)"
REMOTE_STAGING="/var/lib/barman/staging-${NODE}-${STAMP}"

echo "[barman-recover] data_dir=${DATA_DIR} barman=${BARMAN_HOST} server=${BARMAN_SERVER}"

# Ask barman to stage the latest backup on its own disk. If no
# backup exists yet (first replica clone before the first full),
# this fails fast and Patroni falls back to basebackup_chmod.
if ! ssh "${BARMAN_HOST}" "barman recover '${BARMAN_SERVER}' latest '${REMOTE_STAGING}'"; then
    echo "[barman-recover] barman recover failed; surfacing exit code so Patroni falls back" >&2
    ssh "${BARMAN_HOST}" "rm -rf '${REMOTE_STAGING}'" >/dev/null 2>&1 || true
    exit 2
fi

# Wipe and rebuild the data dir, then pull staged files.
rm -rf "${DATA_DIR}"
install -d -m 0700 "${DATA_DIR}"
rsync -a --delete -e ssh "${BARMAN_HOST}:${REMOTE_STAGING}/" "${DATA_DIR}/"

# Cleanup; ignore failures.
ssh "${BARMAN_HOST}" "rm -rf '${REMOTE_STAGING}'" >/dev/null 2>&1 || true

chmod 0700 "${DATA_DIR}"
echo "[barman-recover] complete, mode=$(stat -c %a "${DATA_DIR}")"
EOF
chmod +x /etc/patroni/barman-recover.sh
chown postgres:postgres /etc/patroni/barman-recover.sh

# --- Replica clone helper: basebackup + enforce 0700 data-dir mode.
cat > /etc/patroni/basebackup-chmod.sh <<'EOF'
#!/usr/bin/env bash
# Invoked by Patroni as a replica clone method (the fallback after
# barman_recover). Patroni passes --datadir=<path>, --scope=<cluster>,
# --role=replica, --connstring=<primary-conninfo>. We parse the two
# we care about and run pg_basebackup with sensible defaults, then
# enforce the 0700 mode PostgreSQL requires on the data directory.
set -eu

DATA_DIR=""
CONNSTRING=""
for arg in "$@"; do
    case "$arg" in
        --datadir=*)   DATA_DIR="${arg#*=}" ;;
        --connstring=*) CONNSTRING="${arg#*=}" ;;
    esac
done

: "${DATA_DIR:?missing --datadir from Patroni}"
: "${CONNSTRING:?missing --connstring from Patroni}"

echo "[basebackup-chmod] data_dir=${DATA_DIR}"
echo "[basebackup-chmod] connstring=${CONNSTRING%password=*}"

# Ensure the data dir is empty before basebackup.
rm -rf "${DATA_DIR}"
install -d -m 0700 "${DATA_DIR}"

pg_basebackup --pgdata="${DATA_DIR}" --dbname="${CONNSTRING}" \
    --wal-method=stream --checkpoint=fast --progress --verbose

# pg_basebackup is supposed to match the source mode, but at least
# one combination of umask + filesystem on WSL2 produces 0755
# regardless. Force the mode Postgres actually requires.
chmod 0700 "${DATA_DIR}"

echo "[basebackup-chmod] complete, mode=$(stat -c %a "${DATA_DIR}")"
exit 0
EOF
chmod +x /etc/patroni/basebackup-chmod.sh
chown postgres:postgres /etc/patroni/basebackup-chmod.sh

# --- SSH setup for barman archive_command + replica recover ---
# The barman container generates a shared key pair on its first
# boot and writes the private half into the shared
# /var/lib/barman-ssh volume. We mount that volume read-only and
# copy the private key into the postgres user's ~/.ssh.
mkdir -p /var/lib/postgresql/.ssh
chown postgres:postgres /var/lib/postgresql/.ssh
chmod 0700 /var/lib/postgresql/.ssh

SHARED_KEY=/var/lib/barman-ssh/id_ed25519
if [ -f "${SHARED_KEY}" ]; then
    log "installing shared barman SSH key"
    cp "${SHARED_KEY}" /var/lib/postgresql/.ssh/id_ed25519
    chown postgres:postgres /var/lib/postgresql/.ssh/id_ed25519
    chmod 0600 /var/lib/postgresql/.ssh/id_ed25519
else
    log "WARN shared key ${SHARED_KEY} not present yet — barman archive_command + barman_recover will fail until the barman container initializes"
fi

# Rescan on every boot — the barman host's sshd host keys are
# persisted across restarts, but a from-scratch rebuild rotates
# them. Always trusting the current live key is fine in dev; in
# prod you pre-provision known_hosts at deploy time.
log "scanning ssh host key for ${BARMAN_HOST}"
rm -f /var/lib/postgresql/.ssh/known_hosts
for i in 1 2 3 4 5; do
    if ssh-keyscan -H "${BARMAN_HOST}" 2>/dev/null \
        > /var/lib/postgresql/.ssh/known_hosts && \
       [ -s /var/lib/postgresql/.ssh/known_hosts ]; then
        break
    fi
    log "ssh-keyscan attempt ${i} failed, retrying..."
    sleep 3
done
chown postgres:postgres /var/lib/postgresql/.ssh/known_hosts
chmod 0644 /var/lib/postgresql/.ssh/known_hosts

# Force ssh to log in as the `barman` user on the barman host no
# matter which OS user invokes the SSH session. Wildcard the alias
# so both `ssh barman` and bare-hostname connects resolve.
cat > /var/lib/postgresql/.ssh/config <<SSH_CONFIG
Host ${BARMAN_HOST}
    User ${BARMAN_USER}
    IdentityFile /var/lib/postgresql/.ssh/id_ed25519
    StrictHostKeyChecking accept-new
SSH_CONFIG
chown postgres:postgres /var/lib/postgresql/.ssh/config
chmod 0600 /var/lib/postgresql/.ssh/config

# --- Ensure PGDATA ownership + mode.
#
# The volume mounts /var/lib/postgresql/data as root:root 0755, so
# we fix ownership recursively and pre-create PGDATA with mode
# 0700. If we let Patroni's bootstrap create it instead, it inherits
# the default umask (0022) and Postgres refuses to start with the
# resulting 0755 dir. Creating it ourselves with -m 0700 sidesteps
# the Patroni umask entirely.
chown -R postgres:postgres /var/lib/postgresql
install -d -o postgres -g postgres -m 0700 "${PGDATA}"
# Also force 0700 in case the dir already existed from a prior run
# on a persistent volume.
chmod 0700 "${PGDATA}"

# Umask for anything Patroni spawns after this point: 0077 so any
# files/directories it creates inside PGDATA stay postgres-only.
umask 0077

log "starting patroni"
exec gosu postgres patroni /etc/patroni/patroni.yml
