#!/usr/bin/env bash
# Spin up a 3-node K3s-in-Docker cluster for Helm chart iteration.
#
# Day-to-day Laravel + agent-worker dev still uses `./vendor/bin/sail`
# (docker-compose). This script is for testing the Helm chart's
# rendered K8s manifests against a real cluster — same topology
# (server + 2 agents) and same Traefik ingress as on-prem K3s.
#
# Tears down completely with `k3d-down.sh` — nothing persists.

set -euo pipefail

CLUSTER_NAME="${CLUSTER_NAME:-orbital-dev}"
NAMESPACE="${NAMESPACE:-orbital}"
RELEASE="${RELEASE:-orbital}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CHART_DIR="$(cd "${SCRIPT_DIR}/../helm/orbital" && pwd)"

# ---------------------------------------------------------------------------
# Preflight — bail with a useful message if k3d / helm / kubectl missing
# ---------------------------------------------------------------------------
for cmd in k3d helm kubectl docker; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "ERROR: $cmd not on PATH." >&2
        echo "Install it first:" >&2
        case "$cmd" in
            k3d) echo "  https://k3d.io/#installation" >&2 ;;
            helm) echo "  https://helm.sh/docs/intro/install/" >&2 ;;
            kubectl) echo "  https://kubernetes.io/docs/tasks/tools/" >&2 ;;
            docker) echo "  https://docs.docker.com/engine/install/" >&2 ;;
        esac
        exit 1
    fi
done

# ---------------------------------------------------------------------------
# Conflict check — sail compose stack binds 80/443/5060/etc. on the host.
# k3d's loadbalancer wants 8080/8443 and (optionally) 5060/7443. Warn
# loudly if anything's already there so the failure mode is obvious
# instead of "k3d cluster create hangs."
# ---------------------------------------------------------------------------
warn_port_conflict() {
    local port="$1"
    if ss -tlnp 2>/dev/null | grep -q ":${port} " || \
       lsof -i :"${port}" >/dev/null 2>&1; then
        echo "WARN: port ${port} is already in use." >&2
        echo "      Likely your sail compose stack — \`./vendor/bin/sail down\` first." >&2
    fi
}
warn_port_conflict 8080
warn_port_conflict 8443

# ---------------------------------------------------------------------------
# Cluster lifecycle
# ---------------------------------------------------------------------------
if k3d cluster list -o json 2>/dev/null | grep -q "\"${CLUSTER_NAME}\""; then
    echo "k3d cluster ${CLUSTER_NAME} already exists; skipping create."
else
    echo "==> Creating k3d cluster ${CLUSTER_NAME}…"
    k3d cluster create --config "${SCRIPT_DIR}/k3d-config.yaml"
fi

# ---------------------------------------------------------------------------
# Namespace + the placeholder secret the chart's pods reference
# ---------------------------------------------------------------------------
kubectl create namespace "${NAMESPACE}" --dry-run=client -o yaml | kubectl apply -f -

# Local-dev placeholder secret. NOT for production — these are throwaway
# values just so the chart's `secretRef` references resolve. Real installs
# create this via `kubectl create secret` per `templates/secrets.example.yaml`.
if ! kubectl --namespace "${NAMESPACE}" get secret "${RELEASE}-orbital-app-secrets" >/dev/null 2>&1; then
    echo "==> Creating placeholder ${RELEASE}-orbital-app-secrets…"
    kubectl --namespace "${NAMESPACE}" create secret generic "${RELEASE}-orbital-app-secrets" \
        --from-literal=APP_KEY="base64:$(openssl rand -base64 32)" \
        --from-literal=DB_PASSWORD="orbital-dev-db" \
        --from-literal=REDIS_PASSWORD="orbital-dev-cache" \
        --from-literal=AWS_ACCESS_KEY_ID="orbital-dev-key" \
        --from-literal=AWS_SECRET_ACCESS_KEY="orbital-dev-secret" \
        --from-literal=LIVEKIT_API_KEY="APIorbitaldev" \
        --from-literal=LIVEKIT_API_SECRET="orbital-dev-livekit" \
        --from-literal=ASTERISK_AMI_SECRET="orbital-dev-ami" \
        --from-literal=ANTHROPIC_API_KEY="placeholder" \
        --from-literal=OPENAI_API_KEY="placeholder" \
        --from-literal=ELEVENLABS_API_KEY="placeholder"
fi

# Image pull secret — empty placeholder against the local k3d registry.
# Real installs use the customer's Harbor robot creds.
if ! kubectl --namespace "${NAMESPACE}" get secret orbital-registry-creds >/dev/null 2>&1; then
    echo "==> Creating placeholder orbital-registry-creds (local registry)…"
    kubectl --namespace "${NAMESPACE}" create secret docker-registry orbital-registry-creds \
        --docker-server="orbital-registry:5001" \
        --docker-username="local" \
        --docker-password="local"
fi

# ---------------------------------------------------------------------------
# Helm install
# ---------------------------------------------------------------------------
echo "==> helm upgrade --install ${RELEASE}…"
helm upgrade --install "${RELEASE}" "${CHART_DIR}" \
    --namespace "${NAMESPACE}" \
    --create-namespace \
    -f "${CHART_DIR}/values-onprem-k3s.yaml" \
    --set global.image.registry="orbital-registry:5001" \
    --set global.image.tag="dev" \
    --set global.domain="orbital.localhost" \
    --wait \
    --timeout 5m

echo
echo "Orbital is up. Reach the admin panel:"
echo "  http://orbital.localhost:8080  (add to /etc/hosts: 127.0.0.1 orbital.localhost)"
echo "  - or -"
echo "  kubectl --namespace ${NAMESPACE} port-forward svc/${RELEASE}-orbital-laravel 8000:80"
echo "  open http://localhost:8000"
echo
echo "Tear down with: ./k3d-down.sh"
