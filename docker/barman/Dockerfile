# Barman repository host.
#
# Runs on its own node (not co-located with Postgres) so backups
# survive a total DB-tier failure. Replaces the previous
# pgBackRest repo since pgBackRest is unmaintained.
#
# Topology:
#   - barman cron (every minute) drives `barman receive-wal` (which
#     in turn runs pg_receivewal against the cluster's permanent
#     replication slot) and the periodic backup retention checks.
#   - sshd accepts connections from postgres@patroni-* for the
#     archive_command fallback (`barman-wal-archive`) and from the
#     replica clone helper (`barman-recover.sh` rsyncs a staged
#     recovery directory back to the new replica).
#
# Leader discovery is handled by libpq's multi-host conninfo
# (target_session_attrs=read-write) — the orbital.conf points at all
# three Patroni nodes; libpq automatically picks the writer. The
# physical replication slot used by receive-wal is declared as a
# permanent slot in Patroni's DCS config, so it exists on every
# node and survives failover. No leader-watch sidecar required.

FROM debian:bookworm-slim

ARG DEBIAN_FRONTEND=noninteractive

# PGDG repo gives us a consistent Barman version (3.x) and a
# postgresql-client-17 that matches the cluster's server version
# (pg_basebackup / pg_receivewal must match the server major).
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        gnupg \
        ca-certificates \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
         -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
         > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends \
        barman \
        barman-cli \
        barman-cli-cloud \
        postgresql-client-17 \
        openssh-server \
        openssh-client \
        gettext-base \
        rsync \
        cron \
        jq \
        tini \
        gosu \
        procps \
    && rm -rf /var/lib/apt/lists/*

# The Debian package creates the `barman` user with HOME at
# /var/lib/barman (which doubles as the repo data dir — barman's
# own `barman_home` config also points there). Pin uid to 5433 so
# volume ownership is deterministic across image rebuilds and across
# nodes that share the SSH-key volume. Relocate HOME to /home/barman
# so the user's dotfiles (.pgpass, .ssh) don't sit inside the repo
# volume — keeps backups and credentials cleanly separated.
RUN if id -u barman >/dev/null 2>&1; then \
        usermod -u 5433 -d /home/barman barman && groupmod -g 5433 barman; \
    else \
        groupadd -g 5433 barman \
        && useradd -u 5433 -g 5433 -m -d /home/barman -s /bin/bash barman; \
    fi \
 && mkdir -p /var/lib/barman /var/log/barman /etc/barman /etc/barman.d \
             /home/barman/.ssh /var/lib/barman-ssh \
 && chown -R barman:barman \
        /var/lib/barman \
        /var/log/barman \
        /etc/barman \
        /etc/barman.d \
        /home/barman \
        /var/lib/barman-ssh

# Templates rendered by entrypoint via envsubst.
# Kept under /usr/local/share/barman-orbital so they're outside
# /etc/barman.d (which Barman scans for *.conf) and outside
# /etc/barman/ (which entrypoint chowns to barman:barman). Avoids
# Barman tripping over .tmpl files on restart.
RUN install -d -m 0755 /usr/local/share/barman-orbital
COPY barman.conf.tmpl /usr/local/share/barman-orbital/barman.conf.tmpl
COPY orbital.conf.tmpl /usr/local/share/barman-orbital/orbital.conf.tmpl
COPY sshd_config /etc/ssh/sshd_config.orbital
COPY entrypoint.sh /usr/local/bin/barman-entrypoint
RUN chmod +x /usr/local/bin/barman-entrypoint \
 && mkdir -p /run/sshd

EXPOSE 22

# Repo data + shared SSH key volume + sshd host keys volume.
# /var/lib/barman-ssh is a SHARED volume mounted read-only by Patroni
# nodes so they can read the private key the repo generates on first
# boot. /etc/ssh/keys persists sshd host keys across container
# rebuilds.
VOLUME ["/var/lib/barman", "/var/lib/barman-ssh", "/etc/ssh/keys"]

ENTRYPOINT ["tini", "--", "/usr/local/bin/barman-entrypoint"]
