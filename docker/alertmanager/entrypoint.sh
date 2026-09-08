#!/usr/bin/env bash
#
# Alertmanager entrypoint — renders alertmanager.yml.tmpl via
# envsubst, because Alertmanager has no native environment-variable
# expansion (see the Dockerfile).
#
# Every variable has a default so the container starts on a fresh
# clone with nothing configured. What it does in that state is
# deliberate: it routes to a receiver with no delivery targets, so
# alerts are grouped, deduplicated, and visible at /alertmanager
# while nothing is emailed to a placeholder address. An operator
# turns delivery on by setting ALERT_EMAIL_TO.

set -euo pipefail

log() { echo "[alertmanager] $*" >&2; }

# Mailpit in dev — it accepts anything on 1025 and shows it in a web
# UI, so alert formatting can be checked without sending real mail.
export ALERT_SMTP_SMARTHOST="${ALERT_SMTP_SMARTHOST:-mailpit:1025}"
export ALERT_SMTP_FROM="${ALERT_SMTP_FROM:-alerts@orbital.test}"
export ALERT_SMTP_USERNAME="${ALERT_SMTP_USERNAME:-}"
export ALERT_SMTP_PASSWORD="${ALERT_SMTP_PASSWORD:-}"
export ALERT_EMAIL_TO="${ALERT_EMAIL_TO:-}"
export ALERT_WEBHOOK_URL="${ALERT_WEBHOOK_URL:-}"
export ALERT_EXTERNAL_URL="${ALERT_EXTERNAL_URL:-http://localhost:9093}"

# Mailpit and most internal relays speak plaintext on the LAN.
# Alertmanager defaults require_tls to true, which fails against
# both, so it is explicit here rather than silently broken.
export ALERT_SMTP_REQUIRE_TLS="${ALERT_SMTP_REQUIRE_TLS:-false}"

# Receivers are rendered only when they have somewhere to send.
# Alertmanager rejects an `email_configs` entry with an empty `to`,
# so an unconfigured install would crash-loop rather than start
# quietly.
if [ -n "${ALERT_EMAIL_TO}" ]; then
    # Auth lines are omitted entirely rather than emitted empty. An
    # internal relay that accepts unauthenticated mail from the LAN is
    # the common case, and `auth_username: ''` asks Alertmanager to
    # attempt a login with no credentials against a server that never
    # offered AUTH.
    if [ -n "${ALERT_SMTP_USERNAME}" ]; then
        ALERT_SMTP_AUTH=$(printf "        auth_username: '%s'\n        auth_password: '%s'" \
            "${ALERT_SMTP_USERNAME}" "${ALERT_SMTP_PASSWORD}")
    else
        ALERT_SMTP_AUTH="        # no SMTP auth configured"
    fi

    ALERT_EMAIL_BLOCK=$(cat <<EOF
    email_configs:
      - to: '${ALERT_EMAIL_TO}'
        from: '${ALERT_SMTP_FROM}'
        smarthost: '${ALERT_SMTP_SMARTHOST}'
${ALERT_SMTP_AUTH}
        require_tls: ${ALERT_SMTP_REQUIRE_TLS}
        send_resolved: true
        headers:
          Subject: '[Orbital] {{ .Status | toUpper }} {{ .CommonLabels.alertname }}'
EOF
)
else
    ALERT_EMAIL_BLOCK="    # ALERT_EMAIL_TO not set — no email delivery configured."
    log "ALERT_EMAIL_TO is not set; alerts will group and dedupe but will not be emailed"
fi

if [ -n "${ALERT_WEBHOOK_URL}" ]; then
    ALERT_WEBHOOK_BLOCK=$(cat <<EOF
    webhook_configs:
      - url: '${ALERT_WEBHOOK_URL}'
        send_resolved: true
EOF
)
else
    ALERT_WEBHOOK_BLOCK="    # ALERT_WEBHOOK_URL not set — no webhook delivery configured."
fi

export ALERT_EMAIL_BLOCK ALERT_WEBHOOK_BLOCK

envsubst < /etc/alertmanager/alertmanager.yml.tmpl \
    > /etc/alertmanager/alertmanager.yml

# Validate before handing off. A config error at startup is far
# easier to diagnose than one that surfaces as alerts silently not
# arriving three weeks later.
amtool check-config /etc/alertmanager/alertmanager.yml

chown -R nobody:nobody /alertmanager

log "starting"
exec su-exec nobody /bin/alertmanager \
    --config.file=/etc/alertmanager/alertmanager.yml \
    --storage.path=/alertmanager \
    --web.external-url="${ALERT_EXTERNAL_URL}" \
    "$@"
