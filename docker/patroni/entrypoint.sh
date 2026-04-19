#!/usr/bin/env bash
#
# Patroni container entrypoint.
#
# 1. Renders patroni.yml.tmpl → /etc/patroni/patroni.yml via envsubst
#    so per-node values (name, tags, etcd endpoints, passwords) are
#    baked in from the environment.
# 2. Renders pgbackrest.conf.tmpl → /etc/pgbackrest/pgbackrest.conf.
# 3. Drops the ssh host-key for the pgbackrest-repo host into the
#    postgres user's known_hosts so archive-push over SSH works
#    without prompting.
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
: "${BACKUP_STANZA:=orbital}"
: "${BACKUP_REPO_HOST:=pgbackrest-repo}"
: "${BACKUP_REPO_USER:=pgbackrest}"

export PATRONI_NAME PATRONI_SCOPE PATRONI_ETCD3_HOSTS \
       POSTGRES_PASSWORD PATRONI_REPLICATION_PASSWORD \
       PATRONI_REST_USER PATRONI_REST_PASSWORD \
       PATRONI_TAGS_NOFAILOVER PATRONI_TAGS_NOSYNC \
       BACKUP_STANZA BACKUP_REPO_HOST BACKUP_REPO_USER \
       PGDATA

# --- Render Patroni config ---
log "rendering /etc/patroni/patroni.yml for node ${PATRONI_NAME}"
envsubst < /etc/patroni/patroni.yml.tmpl > /etc/patroni/patroni.yml
chown postgres:postgres /etc/patroni/patroni.yml
chmod 0640 /etc/patroni/patroni.yml

# --- Render pgBackRest config ---
log "rendering /etc/pgbackrest/pgbackrest.conf for stanza ${BACKUP_STANZA}"
envsubst < /etc/pgbackrest/pgbackrest.conf.tmpl > /etc/pgbackrest/pgbackrest.conf
chown postgres:postgres /etc/pgbackrest/pgbackrest.conf
chmod 0640 /etc/pgbackrest/pgbackrest.conf

# --- Post-init hook (runs once on cluster bootstrap) ---
# Creates the pgvector extension and the app user/database so the
# Orbital Laravel layer can connect immediately after cluster up.
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
  END
  \\\$\\\$;
  CREATE DATABASE ${DB_DATABASE:-orbital} OWNER ${DB_USERNAME:-orbital};
SQL
psql -v ON_ERROR_STOP=1 -U postgres -d ${DB_DATABASE:-orbital} <<SQL
  CREATE EXTENSION IF NOT EXISTS vector;
SQL
EOF
chmod +x /etc/patroni/post-init.sh
chown postgres:postgres /etc/patroni/post-init.sh

# --- pgBackRest restore helper (used by Patroni create_replica_methods) ---
cat > /etc/patroni/pgbackrest-restore.sh <<'EOF'
#!/usr/bin/env bash
set -eu
STANZA="${BACKUP_STANZA:-orbital}"
DATA_DIR="${1:-$PGDATA}"
# Wipe and restore — Patroni passes us the data dir as $1 when
# it's calling us as a replica clone method. --delta avoids a
# full wipe when a partial data dir already exists.
exec pgbackrest --stanza="${STANZA}" --delta --type=default \
     --pg1-path="${DATA_DIR}" restore
EOF
chmod +x /etc/patroni/pgbackrest-restore.sh
chown postgres:postgres /etc/patroni/pgbackrest-restore.sh

# --- Replica clone helper: basebackup + enforce 0700 data-dir mode.
cat > /etc/patroni/basebackup-chmod.sh <<'EOF'
#!/usr/bin/env bash
# Invoked by Patroni as a replica clone method. Patroni passes
# --datadir=<path>, --scope=<cluster>, --role=replica,
# --connstring=<primary-conninfo>. We parse the two we care about
# and run pg_basebackup with sensible defaults, then enforce the
# 0700 mode PostgreSQL requires on the data directory.
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

# --- SSH setup for pgbackrest archive-push ---
# The pgbackrest-repo container generates a shared key pair on its
# first boot and writes the private half into the shared
# /var/lib/pgbackrest-ssh volume. We mount that volume read-only and
# copy the private key into the postgres user's ~/.ssh.
mkdir -p /var/lib/postgresql/.ssh
chown postgres:postgres /var/lib/postgresql/.ssh
chmod 0700 /var/lib/postgresql/.ssh

SHARED_KEY=/var/lib/pgbackrest-ssh/id_ed25519
if [ -f "${SHARED_KEY}" ]; then
    log "installing shared pgbackrest SSH key"
    cp "${SHARED_KEY}" /var/lib/postgresql/.ssh/id_ed25519
    chown postgres:postgres /var/lib/postgresql/.ssh/id_ed25519
    chmod 0600 /var/lib/postgresql/.ssh/id_ed25519
else
    log "WARN shared key ${SHARED_KEY} not present yet — pgbackrest archive-push will fail until the repo container initializes"
fi

# Rescan on every boot — the repo host's sshd host keys are
# persisted across restarts, but a from-scratch rebuild of the
# repo container will rotate them. Always trusting the current
# live key is fine in dev; in prod you pre-provision known_hosts
# at deploy time.
log "scanning ssh host key for ${BACKUP_REPO_HOST}"
rm -f /var/lib/postgresql/.ssh/known_hosts
for i in 1 2 3 4 5; do
    if ssh-keyscan -H "${BACKUP_REPO_HOST}" 2>/dev/null \
        > /var/lib/postgresql/.ssh/known_hosts && \
       [ -s /var/lib/postgresql/.ssh/known_hosts ]; then
        break
    fi
    log "ssh-keyscan attempt ${i} failed, retrying..."
    sleep 3
done
chown postgres:postgres /var/lib/postgresql/.ssh/known_hosts
chmod 0644 /var/lib/postgresql/.ssh/known_hosts

# Force ssh to log in as the `pgbackrest` user on the repo host
# no matter which OS user invokes pgbackrest on the DB node.
cat > /var/lib/postgresql/.ssh/config <<SSH_CONFIG
Host ${BACKUP_REPO_HOST}
    User ${BACKUP_REPO_USER}
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
