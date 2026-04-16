#!/bin/sh
# acme.sh deploy hook — called after each successful cert renewal.
#
# POSTs to the Laravel webhook endpoint so the app can dispatch
# service-reload jobs. This keeps all privilege escalation inside
# Laravel's job worker (which already has access to AMI, JSON-RPC,
# etc.) instead of mounting the Docker socket into the acme container.
#
# The ACME_WEBHOOK_TOKEN env var must match what Laravel has in
# config('tls.webhook_token') — both come from the same .env key.
#
# If the webhook fails (Laravel is down, token mismatch, network
# issue), the cert is STILL renewed on disk — services just don't
# reload until the next container restart. The error is logged
# but doesn't block acme.sh from completing the renewal.

WEBHOOK_URL="${ACME_WEBHOOK_URL:-http://orbital.test/api/tls/renewed}"
WEBHOOK_TOKEN="${ACME_WEBHOOK_TOKEN:-}"

if [ -z "$WEBHOOK_TOKEN" ]; then
    echo "[deploy-hook] WARNING: ACME_WEBHOOK_TOKEN is empty — skipping webhook call."
    echo "[deploy-hook] Cert renewed on disk. Services will pick it up on next restart."
    exit 0
fi

echo "[deploy-hook] Notifying Laravel at ${WEBHOOK_URL}..."
HTTP_CODE=$(wget -q -O /dev/null -S --post-data="" \
    --header="Authorization: Bearer ${WEBHOOK_TOKEN}" \
    --header="Content-Type: application/json" \
    "${WEBHOOK_URL}" 2>&1 | grep "HTTP/" | tail -1 | awk '{print $2}')

if [ "$HTTP_CODE" = "202" ] || [ "$HTTP_CODE" = "200" ]; then
    echo "[deploy-hook] Laravel acknowledged (HTTP ${HTTP_CODE}). Services will reload."
else
    echo "[deploy-hook] WARNING: Laravel returned HTTP ${HTTP_CODE:-???}. Cert renewed on disk but services may not have reloaded."
fi

exit 0
