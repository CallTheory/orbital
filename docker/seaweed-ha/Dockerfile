# SeaweedFS HA image — bundles the static `weed` binary from the
# official image onto an Alpine base so we have a shell + envsubst
# for runtime templating of filer.toml (passwords, sentinel
# endpoints).
#
# Masters and volume servers don't need the templating but use the
# same image for uniformity. The entrypoint short-circuits to
# `weed $@` when the first arg isn't `filer`.

FROM chrislusf/seaweedfs:latest AS upstream

FROM alpine:3.19

RUN apk add --no-cache bash gettext tini su-exec ca-certificates curl

COPY --from=upstream /usr/bin/weed /usr/bin/weed

COPY filer.toml /etc/seaweedfs/filer.toml.tmpl
COPY s3.json /etc/seaweedfs/s3.json.tmpl
COPY entrypoint.sh /usr/local/bin/seaweed-entrypoint
RUN chmod +x /usr/local/bin/seaweed-entrypoint \
 && mkdir -p /data /etc/seaweedfs

EXPOSE 9333 19333 8080 18080 8888 18888 8333

VOLUME ["/data"]

ENTRYPOINT ["tini", "--", "/usr/local/bin/seaweed-entrypoint"]
