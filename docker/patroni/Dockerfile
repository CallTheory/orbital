# Orbital Patroni image — PostgreSQL 17 + pgvector + Patroni.
#
# Extends the same pgvector/pgvector:pg17 base the non-HA pgsql
# service uses, so behavior is identical at the SQL layer. Patroni
# wraps Postgres with leader election (via etcd) and streaming
# replication orchestration. The Barman client tooling
# (`barman-cli`) is installed so each DB node can:
#   - push WAL via `barman-wal-archive` (archive_command fallback),
#   - replay WAL via `barman-wal-restore` (restore_command),
#   - clone a fresh replica via `barman-recover.sh` (Patroni
#     create_replica_methods).

FROM pgvector/pgvector:pg17

ARG DEBIAN_FRONTEND=noninteractive

# System packages:
# - python3 / python3-venv: Patroni runtime
# - barman-cli, barman-cli-cloud: archive_command / restore_command
#   helpers + cloud destination support for Phase B
# - gettext-base: envsubst for templating patroni.yml
# - openssh-client + rsync: replica clone helper streams a staged
#   recovery directory from the barman host
# - gosu: drop to postgres user in entrypoint
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 \
        python3-venv \
        python3-pip \
        barman-cli \
        barman-cli-cloud \
        gettext-base \
        openssh-client \
        rsync \
        curl \
        gosu \
        tini \
    && rm -rf /var/lib/apt/lists/*

# Install Patroni into an isolated venv so upstream Python package
# upgrades in the base image never collide with Patroni's pins.
RUN python3 -m venv /opt/patroni \
 && /opt/patroni/bin/pip install --no-cache-dir --upgrade pip \
 && /opt/patroni/bin/pip install --no-cache-dir \
        'patroni[etcd3]==4.0.4' \
        'psycopg2-binary==2.9.9'

ENV PATH="/opt/patroni/bin:${PATH}" \
    PGDATA=/var/lib/postgresql/data/pgdata \
    PATRONI_CONFIG=/etc/patroni/patroni.yml

# Patroni config template — entrypoint will envsubst it on boot.
COPY patroni.yml.tmpl /etc/patroni/patroni.yml.tmpl
COPY entrypoint.sh /usr/local/bin/patroni-entrypoint
RUN chmod +x /usr/local/bin/patroni-entrypoint

# /var/lib/postgresql is a volume; ownership must match the postgres
# UID inside the container. pgvector/pgvector:pg17 uses uid 999.
RUN mkdir -p "${PGDATA}" \
 && chown -R postgres:postgres \
        /var/lib/postgresql \
        /etc/patroni

# 5432 = Postgres, 8008 = Patroni REST (leader/replica health checks)
EXPOSE 5432 8008

ENTRYPOINT ["tini", "--", "/usr/local/bin/patroni-entrypoint"]
