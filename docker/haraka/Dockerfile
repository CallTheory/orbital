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
RUN npm install -g Haraka@3.0.5

# Copy baked config + plugins into the Haraka home directory.
# The config/plugins file enumerates which plugins are active;
# orbital-webhook.js is the one we wrote ourselves.
COPY config/ /haraka/config/
COPY plugins/ /haraka/plugins/

# Haraka listens on 25 inside the container regardless of the host
# port binding. docker-compose maps this to 2525 on the host in dev
# (port 25 is usually blocked on Linux dev hosts by systemd-resolved
# or a stock Postfix).
EXPOSE 25

# Run Haraka with its home pointing at /haraka so it finds our
# config/ and plugins/ folders. Foreground logging to stdout so
# Promtail picks it up via the standard container log path.
CMD ["haraka", "-c", "/haraka"]
