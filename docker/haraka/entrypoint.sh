#!/bin/sh
# Haraka entrypoint: adjusts the baked config for where it runs, then
# starts Haraka in the foreground.
#
#   HARAKA_PORT  port to listen on (default 25). The Helm chart runs as
#                a non-root user, which can't bind 25, so it listens on
#                2525 and Traefik's smtp entrypoint publishes 25.
#   TLS          when a certificate is mounted at /haraka/tls (tls.crt
#                and tls.key, the layout of a Kubernetes TLS Secret),
#                STARTTLS is offered with it. Without one, mail is
#                accepted in plain text, as in the compose dev stack.
set -eu

config=/haraka/config
port="${HARAKA_PORT:-25}"

sed -i "s|^listen = .*|listen = [::0]:${port}|" "${config}/smtp.ini"

if [ -s /haraka/tls/tls.crt ] && [ -s /haraka/tls/tls.key ]; then
    printf '[main]\nkey=/haraka/tls/tls.key\ncert=/haraka/tls/tls.crt\n' > "${config}/tls.ini"
    # tls must run before the plugins that act on the transaction.
    grep -qx 'tls' "${config}/plugins" || sed -i '1i tls' "${config}/plugins"
    echo "haraka: STARTTLS enabled with /haraka/tls/tls.crt"
else
    sed -i '/^tls$/d' "${config}/plugins"
    echo "haraka: no certificate at /haraka/tls, STARTTLS off"
fi

exec haraka -c /haraka
