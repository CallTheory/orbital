#!/usr/bin/env bash
# Build the first-party Orbital images, push them to the local k3d
# registry under a UNIQUE tag, and roll the release onto that tag.
#
# Why a unique tag: reusing ":dev" means `helm upgrade` sees no spec
# change and won't redeploy, and even a `rollout restart` won't re-pull
# under imagePullPolicy=IfNotPresent. A fresh tag per build sidesteps
# both — no pullPolicy=Always, no manual restarts.
#
# Usage:
#   ./local/k3d-build.sh                  # build+push+deploy all 4 images
#   ./local/k3d-build.sh laravel asterisk # only those (others keep current tag)
#   EDGE=1 ./local/k3d-build.sh           # also keep the local Kamailio/rtpengine
#                                         # edge wiring (values-local-edge.yaml)
#
# EDGE=1 preserves the SIP call-path config across a redeploy: it layers
# values-local-edge.yaml on top and re-discovers the k3d node IP that
# Asterisk advertises in SDP. Use it whenever the local edge (local/edge-up.sh)
# is running — a plain redeploy would otherwise reset Asterisk back to the
# non-edge defaults and drop the NodePort exposure mid-session.
#
# Env overrides: NAMESPACE, RELEASE, REGISTRY, VALUES, EDGE.
set -euo pipefail

NAMESPACE="${NAMESPACE:-orbital}"
RELEASE="${RELEASE:-orbital}"
# Host-facing registry endpoint (k3d publishes the registry on localhost;
# the cluster pulls the same blobs via orbital-registry:5001 internally).
REGISTRY="${REGISTRY:-localhost:5001}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
VALUES="${VALUES:-${REPO_ROOT}/helm/orbital/values-onprem-k3s.yaml}"

TAG="dev-$(date +%Y%m%d-%H%M%S)"

# image name → "dockerfile|context" (context is relative to repo root).
declare -A IMAGES=(
  [laravel]="Dockerfile|."
  [agent-worker]="agent-worker/Dockerfile|agent-worker"
  [asterisk]="docker/asterisk/Dockerfile|docker/asterisk"
  [asterisk-config-sync]="docker/asterisk-config-sync/Dockerfile|docker/asterisk-config-sync"
)

# Args = images to (re)build. Any NOT listed are re-tagged from their
# previous local `:dev` build so every image still resolves at ${TAG}
# for the single global.image.tag the release uses. No args = build all.
ALL=(laravel agent-worker asterisk asterisk-config-sync)
declare -A WANT=()
if [ "$#" -gt 0 ]; then
  for name in "$@"; do
    [ -n "${IMAGES[$name]:-}" ] || { echo "ERROR: unknown image '${name}'. Known: ${!IMAGES[*]}" >&2; exit 1; }
    WANT[$name]=1
  done
else
  for name in "${ALL[@]}"; do WANT[$name]=1; done
fi

cd "${REPO_ROOT}"
for name in "${ALL[@]}"; do
  ref="${REGISTRY}/orbital/${name}:${TAG}"
  dev="${REGISTRY}/orbital/${name}:dev"
  if [ -n "${WANT[$name]:-}" ]; then
    spec="${IMAGES[$name]}"; dockerfile="${spec%%|*}"; context="${spec##*|}"
    echo "==> build ${name}  (-f ${dockerfile}  ctx ${context})"
    docker build -t "${ref}" -f "${dockerfile}" "${context}"
    docker tag "${ref}" "${dev}"          # moving "latest local" pointer
    docker push "${ref}"; docker push "${dev}"
  else
    # Reuse the last local build so all 4 exist at ${TAG}.
    if ! docker image inspect "${dev}" >/dev/null 2>&1; then
      echo "ERROR: ${name} not built yet; run once with no args first." >&2
      exit 1
    fi
    echo "==> reuse ${name} (retag :dev -> :${TAG})"
    docker tag "${dev}" "${ref}"
    docker push "${ref}"
  fi
done

# Optional local-edge overlay. When EDGE=1, keep the off-cluster
# Kamailio/rtpengine wiring across the redeploy and re-pin the address
# Asterisk advertises in SDP to a live k3d node IP.
EDGE_ARGS=()
if [ "${EDGE:-0}" = "1" ]; then
  NODE_IP="$(kubectl get nodes -o jsonpath='{.items[0].status.addresses[?(@.type=="InternalIP")].address}')"
  [ -n "${NODE_IP}" ] || { echo "ERROR: EDGE=1 but could not determine a k3d node IP" >&2; exit 1; }
  echo "==> edge overlay on; Asterisk advertises ${NODE_IP}"
  EDGE_ARGS=(
    -f "${REPO_ROOT}/helm/orbital/values-local-edge.yaml"
    --set "asterisk.edgeExpose.externalAddress=${NODE_IP}"
  )
fi

echo "==> helm upgrade ${RELEASE} onto tag ${TAG}"
helm upgrade "${RELEASE}" "${REPO_ROOT}/helm/orbital" \
  --namespace "${NAMESPACE}" \
  -f "${VALUES}" \
  "${EDGE_ARGS[@]}" \
  --set global.image.registry=orbital-registry:5001 \
  --set global.image.tag="${TAG}" \
  --set global.domain=orbital.localhost \
  --set global.appUrl=http://orbital.localhost:8080 \
  --set ingress.tls.enabled=false \
  --timeout 10m

echo
echo "Deployed tag ${TAG}. Watch rollout:"
echo "  kubectl -n ${NAMESPACE} get pods -w"
