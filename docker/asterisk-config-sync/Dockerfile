# asterisk-config-sync — sidecar/init image that keeps an Asterisk pod's
# dialplan + TTS prompts in sync with object storage (SeaweedFS / S3),
# and pokes Asterisk to reload when the dialplan version changes.
#
# This replaces the old shared ReadWriteMany PVC: Laravel/Horizon write
# generated config + prompts to object storage; each Asterisk pod pulls
# its own local copy into an emptyDir via this image. No RWX anywhere.
#
# `mc` is lifted from the official minio/mc image (a docker.io image the
# chart already mirrors) rather than curl'd at build time, so the build
# needs no public internet and the result is air-gap mirrorable like any
# other first-party image.

FROM minio/mc:latest AS mc

FROM alpine:3.20

# bash for the /dev/tcp AMI client; coreutils for `timeout`.
RUN apk add --no-cache bash coreutils ca-certificates

COPY --from=mc /usr/bin/mc /usr/local/bin/mc
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/mc /usr/local/bin/entrypoint.sh

# Mode is the first arg: "once" (initContainer — sync then exit) or
# "loop" (sidecar — sync + reload forever). Defaults to loop.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["loop"]
