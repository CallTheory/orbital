#!/bin/sh
# Wrapper entrypoint for pgAdmin TLS.
# Copies certs from the shared TLS volume to /certs/ (a tmpfs
# volume mounted by docker-compose) with the file names pgAdmin's
# gunicorn expects (server.cert / server.key). Falls back to
# self-signed if no managed cert exists yet.

if [ -f /etc/tls/fullchain.pem ] && [ -s /etc/tls/fullchain.pem ]; then
    cp /etc/tls/fullchain.pem /certs/server.cert
    cp /etc/tls/privkey.pem /certs/server.key
else
    if [ ! -f /certs/server.cert ]; then
        openssl req -x509 -nodes -days 365 \
            -newkey rsa:2048 \
            -keyout /certs/server.key \
            -out /certs/server.cert \
            -subj "/CN=pgadmin-self-signed/O=Orbital" \
            2>/dev/null
    fi
fi

chmod 600 /certs/server.key 2>/dev/null || true

# Hand off to the original pgAdmin entrypoint
exec /entrypoint.sh "$@"
