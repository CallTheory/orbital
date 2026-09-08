# Alertmanager with runtime config templating.
#
# Alertmanager, unlike Loki and Tempo, has NO environment-variable
# expansion of its own — there is no `-config.expand-env` equivalent.
# Without this image the SMTP smarthost, sender, recipient, and
# password would have to be committed to a tracked file, which is
# exactly what .env exists to avoid.
#
# Same shape as docker/seaweed-ha: take the static binary off the
# official image and put it on Alpine so there is a shell and
# envsubst. Alertmanager's own base image is busybox-on-scratch and
# has neither.

FROM prom/alertmanager:latest AS upstream

FROM alpine:3.19

RUN apk add --no-cache bash gettext tini su-exec ca-certificates

COPY --from=upstream /bin/alertmanager /bin/alertmanager
COPY --from=upstream /bin/amtool /bin/amtool

COPY alertmanager.yml.tmpl /etc/alertmanager/alertmanager.yml.tmpl
COPY entrypoint.sh /usr/local/bin/alertmanager-entrypoint
RUN chmod +x /usr/local/bin/alertmanager-entrypoint \
 && mkdir -p /alertmanager \
 && addgroup -g 65534 -S nobody 2>/dev/null || true

EXPOSE 9093

VOLUME ["/alertmanager"]

ENTRYPOINT ["tini", "--", "/usr/local/bin/alertmanager-entrypoint"]
