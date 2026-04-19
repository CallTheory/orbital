#!/usr/bin/env bash
#
# pgBackRest repo entrypoint.
#
# 1. Generate sshd host keys (once per volume).
# 2. Generate the shared key pair used by Patroni nodes to push
#    WAL archives — private half goes into the SHARED ssh-key
#    volume, public half into authorized_keys here. This bootstrap
#    avoids any manual key wrangling.
# 3. Render pgbackrest.conf.
# 4. Start sshd in foreground.

set -euo pipefail

log() { echo "[pgbackrest-repo] $*" >&2; }

: "${BACKUP_STANZA:=orbital}"
: "${PATRONI_NODE_1:=patroni-1}"
: "${PATRONI_NODE_2:=patroni-2}"
: "${PATRONI_NODE_3:=patroni-3}"
: "${PGDATA:=/var/lib/postgresql/data/pgdata}"
export BACKUP_STANZA PATRONI_NODE_1 PATRONI_NODE_2 PATRONI_NODE_3 PGDATA

SSH_SHARED_DIR=/var/lib/pgbackrest-ssh
PRIV=${SSH_SHARED_DIR}/id_ed25519
PUB=${SSH_SHARED_DIR}/id_ed25519.pub

# --- sshd host keys (persisted in /etc/ssh/keys volume) ---
# Without persistence, every container recreate regenerates keys and
# every Patroni node has to re-trust the new fingerprint. Persisting
# the keys keeps known_hosts stable across repo restarts.
mkdir -p /etc/ssh/keys
if [ ! -f /etc/ssh/keys/ssh_host_ed25519_key ]; then
    log "generating sshd host keys into /etc/ssh/keys"
    ssh-keygen -t rsa -N '' -f /etc/ssh/keys/ssh_host_rsa_key
    ssh-keygen -t ecdsa -N '' -f /etc/ssh/keys/ssh_host_ecdsa_key
    ssh-keygen -t ed25519 -N '' -f /etc/ssh/keys/ssh_host_ed25519_key
fi
# Link the persisted keys into /etc/ssh where sshd looks by default
# (our sshd_config.orbital is the one that explicitly names them).
for k in ssh_host_rsa_key ssh_host_ecdsa_key ssh_host_ed25519_key; do
    ln -sf /etc/ssh/keys/"$k" /etc/ssh/"$k"
    ln -sf /etc/ssh/keys/"$k".pub /etc/ssh/"$k".pub
done

cp /etc/ssh/sshd_config.orbital /etc/ssh/sshd_config

# --- client key pair shared with Patroni nodes ---
if [ ! -f "${PRIV}" ]; then
    log "generating shared Patroni→repo ssh key pair at ${PRIV}"
    ssh-keygen -t ed25519 -N '' -C "patroni→pgbackrest-repo" -f "${PRIV}"
    chown pgbackrest:pgbackrest "${PRIV}" "${PUB}"
    chmod 0600 "${PRIV}"
    chmod 0644 "${PUB}"
fi

# --- authorized_keys ---
mkdir -p /home/pgbackrest/.ssh
cp "${PUB}" /home/pgbackrest/.ssh/authorized_keys
chown -R pgbackrest:pgbackrest /home/pgbackrest/.ssh
chmod 0700 /home/pgbackrest/.ssh
chmod 0600 /home/pgbackrest/.ssh/authorized_keys

# Allow the postgres user (as whom pgbackrest runs on DB nodes)
# to identify itself as pgbackrest@repo when connecting. This is
# why DB node entrypoints use `ssh pgbackrest@pgbackrest-repo`.

# --- pgbackrest.conf ---
log "rendering /etc/pgbackrest/pgbackrest.conf (stanza=${BACKUP_STANZA})"
envsubst < /etc/pgbackrest/pgbackrest.conf.tmpl > /etc/pgbackrest/pgbackrest.conf
chown pgbackrest:pgbackrest /etc/pgbackrest/pgbackrest.conf
chmod 0640 /etc/pgbackrest/pgbackrest.conf

log "starting sshd"
# sshd in non-detached mode so tini + docker see it.
exec /usr/sbin/sshd -D -e
