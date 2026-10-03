# asterisk-config-sync — sidecar/init image that keeps an Asterisk pod's
# dialplan + TTS prompts in sync with object storage (SeaweedFS / S3),
# and pokes Asterisk to reload when the dialplan version changes.
#
# This replaces the old shared ReadWriteMany PVC: Laravel/Horizon write
# generated config + prompts to object storage; each Asterisk pod pulls
# its own local copy into an emptyDir via this image. No RWX anywhere.
#
# `rclone` is lifted from the official rclone/rclone image rather than
# curl'd at build time, so the build needs no public internet beyond the
# registry and the result is air-gap mirrorable like any other first-party
# image. (This used minio/mc until MinIO archived the client and pulled
# its images.)

FROM rclone/rclone:1 AS rclone

FROM alpine:3.24

# bash for the /dev/tcp AMI client; coreutils for `timeout`.
RUN apk add --no-cache bash coreutils ca-certificates

COPY --from=rclone /usr/local/bin/rclone /usr/local/bin/rclone
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/rclone /usr/local/bin/entrypoint.sh

# Mode is the first arg: "once" (initContainer — sync then exit) or
# "loop" (sidecar — sync + reload forever). Defaults to loop.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["loop"]
