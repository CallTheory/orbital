#!/usr/bin/env bash
#
# Repair corrupted Valkey AOF tails.
#
# When a Valkey node is SIGKILLed mid-write (e.g. an ungraceful
# `docker compose down` / host shutdown) its append-only file can be
# left with a half-written record. On next boot the node logs:
#
#   # Bad file format reading the append only file appendonly.aof.NN.incr.aof
#
# and crash-loops. Because HAProxy:6379 fronts the Sentinel-elected
# Valkey master, a down master cascades to livekit, livekit-sip,
# horizon, and the seaweed filer (which uses Valkey for metadata),
# which in turn takes down Loki's S3 backend. In other words: one
# corrupt AOF byte can floor half the stack.
#
# This script stops the Valkey nodes, backs up each incr AOF, and runs
# `valkey-check-aof --fix` to truncate only the corrupt tail (typically
# a few KB) before restarting them. Data-preserving.
#
# Usage: bin/fix-valkey-aof.sh
#
set -euo pipefail

# Container name -> data volume. Adjust the project prefix if your
# compose project name differs from "orbital".
PROJECT="${COMPOSE_PROJECT_NAME:-orbital}"
NODES=(valkey-1 valkey-2 valkey-3)
IMAGE="${VALKEY_IMAGE:-${PROJECT}-valkey-1}"

echo ">> Stopping Valkey nodes so the AOF can be repaired offline..."
for n in "${NODES[@]}"; do
    docker stop "${PROJECT}-${n}-1" >/dev/null 2>&1 || true
done

for n in "${NODES[@]}"; do
    vol="${PROJECT}_ha-${n}"
    echo ">> Repairing ${vol}"
    docker run --rm --entrypoint sh -v "${vol}:/var/lib/valkey" "${IMAGE}" -c '
        set -e
        cd /var/lib/valkey/appendonlydir 2>/dev/null || { echo "   no appendonlydir; nothing to fix"; exit 0; }
        manifest=appendonly.aof.manifest
        [ -f "$manifest" ] || { echo "   no manifest; nothing to fix"; exit 0; }
        for incr in appendonly.aof.*.incr.aof; do
            [ -f "$incr" ] || continue
            cp -n "$incr" "$incr.bak.$(date +%s)" 2>/dev/null || cp "$incr" "$incr.bak"
        done
        # --fix prompts [y/N]; feed it "y". Exits non-zero only on a
        # genuinely unrecoverable file, in which case we surface it.
        yes | valkey-check-aof --fix "$manifest"
    '
done

echo ">> Restarting Valkey nodes..."
for n in "${NODES[@]}"; do
    docker start "${PROJECT}-${n}-1" >/dev/null
done

echo ">> Waiting for a Sentinel-connected master..."
sleep 8
docker exec "${PROJECT}-sentinel-1-1" valkey-cli -p 26379 sentinel master orbital 2>/dev/null \
    | grep -A1 '^flags' || true

echo ">> Done. Check 'make health' for downstream recovery."
