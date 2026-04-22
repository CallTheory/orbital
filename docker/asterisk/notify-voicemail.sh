#!/bin/sh
#
# Asterisk externnotify hook. Asterisk invokes this script after
# writing a new voicemail message:
#
#   notify-voicemail.sh <context> <mailbox> <new_count>
#
# We POST the event (plus the most recent WAV path) to Laravel's
# voicemail webhook so the queued transcription + email job picks
# it up. Shared secret gets HMAC'd into the payload for auth.
#
# The WAV lives at:
#   /var/spool/asterisk/voicemail/<context>/<mailbox>/INBOX/msgNNNN.wav
# where NNNN is zero-padded to four digits.
#
# Required env:
#   VOICEMAIL_WEBHOOK_URL   — Laravel endpoint (e.g. http://orbital.test/api/voicemail/received)
#   VOICEMAIL_WEBHOOK_TOKEN — shared secret (matches config/services.php)
#
# Fails silently — Asterisk already wrote the WAV, and we don't want
# a webhook hiccup to interfere with the caller's hang-up.

set -eu

CONTEXT="${1:-}"
MAILBOX="${2:-}"
NEW_COUNT="${3:-0}"

: "${VOICEMAIL_WEBHOOK_URL:=}"
: "${VOICEMAIL_WEBHOOK_TOKEN:=}"

if [ -z "$VOICEMAIL_WEBHOOK_URL" ] || [ -z "$VOICEMAIL_WEBHOOK_TOKEN" ]; then
    echo "[notify-voicemail] webhook URL/token unset — skipping" >&2
    exit 0
fi

# Find the newest WAV in the mailbox's INBOX. Asterisk numbers messages
# sequentially; the highest-numbered WAV is the one that just landed.
INBOX_DIR="/var/spool/asterisk/voicemail/${CONTEXT}/${MAILBOX}/INBOX"
if [ ! -d "$INBOX_DIR" ]; then
    echo "[notify-voicemail] inbox dir missing: $INBOX_DIR" >&2
    exit 0
fi

WAV_PATH="$(ls -1t "$INBOX_DIR"/msg*.wav 2>/dev/null | head -n1 || true)"
if [ -z "$WAV_PATH" ]; then
    echo "[notify-voicemail] no WAV in $INBOX_DIR" >&2
    exit 0
fi

DURATION=0
if command -v soxi >/dev/null 2>&1; then
    DURATION=$(soxi -D "$WAV_PATH" 2>/dev/null | cut -d. -f1 || echo 0)
fi

# Parse caller ID from the sibling .txt metadata Asterisk writes.
# Format includes `callerid=...` and `origtime=...` lines.
META_PATH="$(echo "$WAV_PATH" | sed 's/\.wav$/.txt/')"
CALLER_ID_NUM=""
CALLER_ID_NAME=""
if [ -f "$META_PATH" ]; then
    CALLERID=$(grep -E '^callerid=' "$META_PATH" | cut -d= -f2-)
    # Format is `"Name" <number>`. Strip quotes and pull both halves.
    CALLER_ID_NAME=$(echo "$CALLERID" | sed -n 's/^"\([^"]*\)".*/\1/p')
    CALLER_ID_NUM=$(echo "$CALLERID" | sed -n 's/.*<\([^>]*\)>.*/\1/p')
fi

PAYLOAD=$(cat <<EOF
{
  "context": "$CONTEXT",
  "mailbox": "$MAILBOX",
  "new_count": $NEW_COUNT,
  "recording_path": "$WAV_PATH",
  "duration_seconds": $DURATION,
  "caller_id_num": "$CALLER_ID_NUM",
  "caller_id_name": "$CALLER_ID_NAME"
}
EOF
)

curl --silent --show-error --max-time 5 \
    -H "Content-Type: application/json" \
    -H "X-Voicemail-Token: $VOICEMAIL_WEBHOOK_TOKEN" \
    -d "$PAYLOAD" \
    "$VOICEMAIL_WEBHOOK_URL" \
    > /dev/null 2>&1 || echo "[notify-voicemail] webhook POST failed (non-fatal)" >&2

exit 0
