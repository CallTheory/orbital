# HA Valkey image — extends the official valkey:8-alpine with
# envsubst tooling and two config templates (valkey + sentinel).
# A single image runs as EITHER a data node (valkey-server) or a
# sentinel (valkey-sentinel) depending on the SERVICE env var.
#
# Topology:
#   valkey-1 primary (initial) + valkey-2/3 replicas
#   sentinel-1/2/3 co-located on separate containers, quorum=2
#
# Post-failover the sentinel cluster rewrites the sentinel.conf
# file in-place to remember the new primary — that file therefore
# lives on a persistent volume per sentinel.

FROM valkey/valkey:8-alpine

# envsubst (from gettext) for config templating, tini as init, bash
# for the entrypoint.
RUN apk add --no-cache gettext tini bash su-exec

COPY valkey.conf.tmpl /etc/valkey/valkey.conf.tmpl
COPY sentinel.conf.tmpl /etc/valkey/sentinel.conf.tmpl
COPY entrypoint.sh /usr/local/bin/valkey-ha-entrypoint
RUN chmod +x /usr/local/bin/valkey-ha-entrypoint \
 && mkdir -p /var/lib/valkey /var/lib/sentinel \
 && chown -R valkey:valkey /var/lib/valkey /var/lib/sentinel /etc/valkey

# 6379 = valkey-server, 26379 = valkey-sentinel. The image exposes
# both; each container only listens on one in practice.
EXPOSE 6379 26379

VOLUME ["/var/lib/valkey", "/var/lib/sentinel"]

ENTRYPOINT ["tini", "--", "/usr/local/bin/valkey-ha-entrypoint"]
