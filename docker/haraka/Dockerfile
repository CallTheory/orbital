# Haraka SMTP → HTTP webhook shim.
#
# Haraka's only job in Orbital is to accept inbound SMTP at the
# network edge and POST the raw RFC822 + envelope to Laravel's
# /api/mail/inbound endpoint. It stores nothing, runs no mailboxes,
# and holds no state. All parsing, routing, threading, and storage
# lives in Laravel — this container is a pure SMTP→HTTP translator,
# the self-hosted equivalent of SendGrid Inbound Parse.
#
# Base: official node:22-alpine (tiny, apk-only deps).
#
# At container start, `haraka -c /haraka` runs with our baked config
# under /haraka. The custom queue plugin at /haraka/plugins/
# orbital-webhook.js fires at end-of-DATA, reads the transaction
# message stream, and POSTs it to Laravel.

FROM node:22-alpine

# Install Haraka globally. Pin a version so image builds are
# reproducible — bump intentionally when we want a newer release.
# --omit=optional skips Haraka's optional plugins (graph, syslog, ...):
# Orbital uses only the built-in tls plugin and its own webhook, and the
# optional ones drag in native modules and old dependencies that fail
# Harbor's vulnerability policy (3.0.5's tree had a Critical finding).
RUN npm install -g --omit=optional Haraka@3.3.5 \
    && npm cache clean --force

# Copy baked config + plugins into the Haraka home directory.
# The config/plugins file enumerates which plugins are active;
# orbital-webhook.js is the one we wrote ourselves.
COPY config/ /haraka/config/
COPY plugins/ /haraka/plugins/
COPY entrypoint.sh /usr/local/bin/haraka-entrypoint

# Haraka's own default config for everything we don't ship. Run with
# `-c /haraka` it reads only this folder, and since 3.3 a missing
# connection.ini (the SMTP greeting, among others) crashes it on the first
# connection. -n keeps our files (plugins, smtp.ini, ...) as they are.
RUN cp -rn /usr/local/lib/node_modules/Haraka/config/. /haraka/config/

# Diffie-Hellman parameters for STARTTLS. Without the file, Haraka runs
# `openssl dhparam` at startup, and this image has no openssl binary, so
# it would crash. Generated once here; the tool is removed again.
RUN apk add --no-cache --virtual .dhparam openssl \
    && openssl dhparam -out /haraka/config/dhparams.pem 2048 \
    && apk del .dhparam

# The entrypoint rewrites config at start (listen port, STARTTLS), so the
# config folder belongs to `node` (uid 1000): the Helm chart runs pods as
# that uid, while compose still runs the container as root.
RUN chown -R node:node /haraka

# Haraka listens on 25 by default. docker-compose maps this to 2525 on
# the host in dev (port 25 is usually blocked on Linux dev hosts by
# systemd-resolved or a stock Postfix). The Helm chart sets HARAKA_PORT
# to 2525, since its non-root pods can't bind 25; Traefik publishes 25.
EXPOSE 25 2525

# The entrypoint applies HARAKA_PORT and a mounted certificate, then
# runs Haraka with its home at /haraka so it finds our config/ and
# plugins/ folders. Foreground logging to stdout so Promtail picks it
# up via the standard container log path.
CMD ["haraka-entrypoint"]
