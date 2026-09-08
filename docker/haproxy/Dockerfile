# Internal HAProxy — L4/L7 frontend for the HA data tier.
#
# Not customer-facing; this sits between Laravel/agent-worker/etc.
# and the Patroni / Sentinel / SeaweedFS clusters. Terminating any
# failover routing logic here keeps app config simple: apps talk
# to stable hostnames like `haproxy:5432` (pgsql-rw) and HAProxy
# handles "who is the current primary" via health checks.
#
# For keepalived / VRRP VIPs we defer to production-host
# deployments — docker bridge networks don't carry multicast
# cleanly, and docker's embedded DNS gives us an equivalent
# stable-endpoint behavior in dev by returning the active
# haproxy container's IP on lookup.

FROM haproxy:3.0-alpine

USER root
RUN apk add --no-cache gettext bash

COPY haproxy.cfg.tmpl /etc/haproxy/haproxy.cfg.tmpl
COPY entrypoint.sh /usr/local/bin/haproxy-entrypoint
RUN chmod +x /usr/local/bin/haproxy-entrypoint \
 && mkdir -p /run/haproxy \
 && chown -R haproxy:haproxy /run/haproxy /etc/haproxy

# 5432 = pgsql-rw, 5433 = pgsql-ro, 6379 = valkey, 8333 = seaweed-s3
# 3000 = grafana, 9090 = prometheus, 3100 = loki, 8089 = asterisk-wss,
# 9093 = alertmanager, 3200 = tempo query, 4318 = tempo OTLP ingest,
# 8404 = HAProxy stats UI
EXPOSE 5432 5433 6379 8333 3000 9090 3100 8089 9093 3200 4318 8404

USER haproxy

ENTRYPOINT ["/usr/local/bin/haproxy-entrypoint"]
