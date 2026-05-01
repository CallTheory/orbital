#!/usr/bin/env bash
# Tear down the local k3d cluster + its registry.
# Nothing persists outside Docker; this is destructive but safe.

set -euo pipefail

CLUSTER_NAME="${CLUSTER_NAME:-orbital-dev}"

echo "==> k3d cluster delete ${CLUSTER_NAME}…"
k3d cluster delete "${CLUSTER_NAME}" || true

# k3d auto-cleans the registry container when the cluster goes,
# but a manual delete here covers the edge case where the registry
# was created standalone (or a prior run failed mid-create).
docker rm -f orbital-registry 2>/dev/null || true

echo "Done. Bring it back with ./k3d-up.sh"
