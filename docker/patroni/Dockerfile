# Orbital Patroni image — PostgreSQL 17 + pgvector + Patroni.
#
# Extends the same pgvector/pgvector:pg17 base the non-HA pgsql
# service uses, so behavior is identical at the SQL layer. Patroni
# wraps Postgres with leader election (via etcd) and streaming
# replication orchestration, and pgBackRest client tooling is
# installed so each DB node can ship WAL archives to the
# dedicated pgbackrest-repo host.

FROM pgvector/pgvector:pg17

ARG DEBIAN_FRONTEND=noninteractive

# System packages:
# - python3 / python3-venv: Patroni runtime
# - pgbackrest: WAL archiving + base-backup client
# - gettext-base: envsubst for templating patroni.yml
# - openssh-client: pgbackrest ships WAL over SSH to the repo host
# - gosu: drop to postgres user in entrypoint
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 \
        python3-venv \
        python3-pip \
        pgbackrest \
        gettext-base \
        openssh-client \
        curl \
        gosu \
        tini \
    && rm -rf /var/lib/apt/lists/* \
    # Remove the Debian-shipped /etc/pgbackrest.conf stub so our
    # envsubst-rendered /etc/pgbackrest/pgbackrest.conf is the only
    # config pgBackRest reads. Otherwise the two are merged and the
    # stub's `repo1-path` without host coordinates can mask the
    # real SSH-based repo we want.
    && rm -f /etc/pgbackrest.conf

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
COPY pgbackrest.conf.tmpl /etc/pgbackrest/pgbackrest.conf.tmpl
COPY entrypoint.sh /usr/local/bin/patroni-entrypoint
RUN chmod +x /usr/local/bin/patroni-entrypoint

# /var/lib/postgresql is a volume; ownership must match the postgres
# UID inside the container. pgvector/pgvector:pg17 uses uid 999.
RUN mkdir -p "${PGDATA}" /var/lib/postgresql/pgbackrest /var/log/pgbackrest \
 && chown -R postgres:postgres \
        /var/lib/postgresql \
        /var/log/pgbackrest \
        /etc/patroni \
        /etc/pgbackrest

# 5432 = Postgres, 8008 = Patroni REST (leader/replica health checks)
EXPOSE 5432 8008

ENTRYPOINT ["tini", "--", "/usr/local/bin/patroni-entrypoint"]
