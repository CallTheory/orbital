#!/usr/bin/env bash
# Tear down the local Kamailio + rtpengine edge. Leaves the k3d cluster
# (and its values-local-edge deployment) alone.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export K3D_NETWORK="k3d-${CLUSTER_NAME:-orbital-dev}"
docker compose -f "${SCRIPT_DIR}/edge/docker-compose.yml" down
echo "Edge down. (Cluster untouched — revert it with a normal ./local/k3d-build.sh if you want.)"
