# pgBackRest repository host.
#
# Runs on its own node (not co-located with Postgres) so backups
# survive a total DB-tier failure. The Patroni nodes push WAL
# segments and full/incremental backups here over SSH.
#
# pgBackRest must run the SAME major version on client and server.
# The Patroni image (pgvector/pgvector:pg17) bundles pgbackrest
# 2.58 via the PGDG apt repo; plain `debian:bookworm-slim` ships
# 2.45 from the default Debian pool, which breaks the remote
# protocol ("expected value '2.58.0' for greeting key 'version'
# but got '2.45'"). Pulling from PGDG on this side keeps both ends
# on 2.58 so archive-push / backup / restore work across SSH.

FROM debian:bookworm-slim

ARG DEBIAN_FRONTEND=noninteractive

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
        pgbackrest \
        openssh-server \
        openssh-client \
        gettext-base \
        tini \
        gosu \
        procps \
    && rm -rf /var/lib/apt/lists/*

# pgBackRest repo user + group (uid 5432 mirrors Postgres conventions).
# On Debian, useradd without -p leaves the password field blank which
# sshd accepts for key-based auth.
RUN groupadd -g 5432 pgbackrest \
 && useradd -u 5432 -g 5432 -m -s /bin/bash pgbackrest \
 && mkdir -p /var/lib/pgbackrest /var/log/pgbackrest /var/spool/pgbackrest \
             /etc/pgbackrest /home/pgbackrest/.ssh /var/lib/pgbackrest-ssh \
 && chown -R pgbackrest:pgbackrest \
        /var/lib/pgbackrest \
        /var/log/pgbackrest \
        /var/spool/pgbackrest \
        /etc/pgbackrest \
        /home/pgbackrest \
        /var/lib/pgbackrest-ssh \
 # Remove the Debian-shipped /etc/pgbackrest.conf stub so our
 # envsubst-rendered /etc/pgbackrest/pgbackrest.conf is the only
 # config pgBackRest reads.
 && rm -f /etc/pgbackrest.conf

COPY pgbackrest.conf.tmpl /etc/pgbackrest/pgbackrest.conf.tmpl
COPY sshd_config /etc/ssh/sshd_config.orbital
COPY entrypoint.sh /usr/local/bin/pgbackrest-entrypoint
RUN chmod +x /usr/local/bin/pgbackrest-entrypoint \
 && mkdir -p /run/sshd

EXPOSE 22

# Repo data + shared SSH key volume declared so compose / ops can
# mount durable storage. /var/lib/pgbackrest-ssh is deliberately a
# SHARED volume with the Patroni nodes so they can read the
# private key the repo generates on first boot. /etc/ssh/keys is
# where sshd host keys are persisted across restarts.
VOLUME ["/var/lib/pgbackrest", "/var/lib/pgbackrest-ssh", "/etc/ssh/keys"]

ENTRYPOINT ["tini", "--", "/usr/local/bin/pgbackrest-entrypoint"]
