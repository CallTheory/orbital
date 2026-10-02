#!/usr/bin/env bash
# Sync Asterisk dialplan config + TTS prompts from object storage into an
# Asterisk pod's local dirs, and trigger an AMI reload when the dialplan
# version marker changes.
#
# Modes (first arg):
#   once  — sync one time and exit 0. Used as an initContainer so Asterisk
#           boots with current config already on disk.
#   loop  — sync every ${SYNC_INTERVAL_SECONDS}s forever; on a version.txt
#           change, issue `dialplan reload` + `voicemail reload` over AMI.
#
# All inputs come from env (injected via the app-env ConfigMap +
# app-secrets Secret that the Asterisk StatefulSet already mounts):
#   AWS_ENDPOINT / AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_BUCKET
#   ASTERISK_CONFIG_PREFIX   (default asterisk/config)
#   ASTERISK_PROMPTS_PREFIX  (default asterisk/prompts)
#   ASTERISK_AMI_HOST / ASTERISK_AMI_PORT / ASTERISK_AMI_USERNAME / ASTERISK_AMI_SECRET
#   SYNC_INTERVAL_SECONDS    (default 10)
set -euo pipefail

MODE="${1:-loop}"

CONFIG_PREFIX="${ASTERISK_CONFIG_PREFIX:-asterisk/config}"
PROMPTS_PREFIX="${ASTERISK_PROMPTS_PREFIX:-asterisk/prompts}"
CONFIG_DIR="${ASTERISK_CONFIG_DIR:-/etc/asterisk/generated}"
PROMPTS_DIR="${ASTERISK_PROMPTS_DIR:-/var/spool/asterisk/prompts}"

AMI_HOST="${ASTERISK_AMI_HOST:-127.0.0.1}"
AMI_PORT="${ASTERISK_AMI_PORT:-5038}"
AMI_USER="${ASTERISK_AMI_USERNAME:-orbital}"
AMI_SECRET="${ASTERISK_AMI_SECRET:-}"
INTERVAL="${SYNC_INTERVAL_SECONDS:-10}"

REMOTE="obj"
BUCKET_SRC="${REMOTE}:${AWS_BUCKET}"
CONFIG_SRC="${BUCKET_SRC}/${CONFIG_PREFIX}"
PROMPTS_SRC="${BUCKET_SRC}/${PROMPTS_PREFIX}"

log() { echo "[config-sync] $*"; }

configure_rclone() {
  # rclone reads remote config from RCLONE_CONFIG_<NAME>_* env, so no
  # config file is written. Provider "Other" + path-style works against
  # SeaweedFS and Vultr/AWS alike.
  export RCLONE_CONFIG_OBJ_TYPE=s3
  export RCLONE_CONFIG_OBJ_PROVIDER=Other
  export RCLONE_CONFIG_OBJ_ENDPOINT="$AWS_ENDPOINT"
  export RCLONE_CONFIG_OBJ_ACCESS_KEY_ID="$AWS_ACCESS_KEY_ID"
  export RCLONE_CONFIG_OBJ_SECRET_ACCESS_KEY="$AWS_SECRET_ACCESS_KEY"
  export RCLONE_CONFIG_OBJ_REGION="${AWS_DEFAULT_REGION:-us-east-1}"
  export RCLONE_CONFIG_OBJ_FORCE_PATH_STYLE=true
  # Best-effort: make sure the bucket exists so the first sync doesn't
  # error before Laravel has written anything. Harmless if it already
  # exists or if perms disallow it (sync still works once populated).
  rclone mkdir "$BUCKET_SRC" >/dev/null 2>&1 || true
}

# Block until object storage answers — the seaweedfs pod may still be
# coming up when Asterisk schedules. Bounded so a truly-broken endpoint
# doesn't wedge the initContainer forever.
wait_for_object_store() {
  local tries=0
  until rclone lsf --max-depth 1 "$BUCKET_SRC" >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
      log "object store ${AWS_ENDPOINT}/${AWS_BUCKET} unreachable after 60 tries; giving up"
      return 1
    fi
    log "waiting for object store ${AWS_ENDPOINT}/${AWS_BUCKET} (try ${tries})..."
    sleep 2
  done
}

sync_files() {
  mkdir -p "$CONFIG_DIR" "$PROMPTS_DIR"
  # sync picks up edits and prunes client dialplans that were deleted
  # upstream; a missing source prefix errors out without touching the
  # local copy. --exclude /version.txt: the marker isn't Asterisk config.
  rclone sync --exclude "/version.txt" "$CONFIG_SRC" "$CONFIG_DIR" || true
  # Prompts are optional and often absent until the first TTS render, so
  # keep this quiet — a missing source prefix is expected, not an error.
  rclone sync "$PROMPTS_SRC" "$PROMPTS_DIR" >/dev/null 2>&1 || true
}

remote_version() {
  rclone cat "${CONFIG_SRC}/version.txt" 2>/dev/null || echo ""
}

# Minimal AMI client over bash /dev/tcp — logs in, fires the reload
# commands, logs off. Best-effort: a failed reload is logged, not fatal
# (the next version change retries).
ami_reload() {
  if [ -z "$AMI_SECRET" ]; then
    log "ASTERISK_AMI_SECRET empty; skipping reload"
    return 0
  fi
  if ! exec 3<>"/dev/tcp/${AMI_HOST}/${AMI_PORT}" 2>/dev/null; then
    log "AMI connect to ${AMI_HOST}:${AMI_PORT} failed; will retry next change"
    return 1
  fi
  printf 'Action: Login\r\nUsername: %s\r\nSecret: %s\r\n\r\n' "$AMI_USER" "$AMI_SECRET" >&3
  printf 'Action: Command\r\nCommand: dialplan reload\r\n\r\n' >&3
  printf 'Action: Command\r\nCommand: voicemail reload\r\n\r\n' >&3
  printf 'Action: Logoff\r\n\r\n' >&3
  timeout 5 cat <&3 >/dev/null 2>&1 || true
  exec 3<&- 3>&- || true
  log "AMI dialplan+voicemail reload sent"
}

configure_rclone

case "$MODE" in
  once)
    wait_for_object_store || exit 0   # boot Asterisk even if store is late; sidecar catches up
    sync_files
    log "initial sync complete"
    ;;
  loop)
    wait_for_object_store || true
    last_version=""
    while true; do
      sync_files
      current="$(remote_version)"
      if [ -n "$current" ] && [ "$current" != "$last_version" ]; then
        # Reload on first-seen too: the initContainer only syncs at boot,
        # so config generated AFTER Asterisk started would otherwise sit on
        # disk unloaded until the next change. A redundant reload right
        # after boot (when config already existed) is cheap and harmless.
        log "dialplan version changed (${last_version:-<none>} -> $current); reloading"
        ami_reload || true
        last_version="$current"
      fi
      sleep "$INTERVAL"
    done
    ;;
  *)
    log "unknown mode '$MODE' (expected 'once' or 'loop')"
    exit 2
    ;;
esac
