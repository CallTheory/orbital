#!/usr/bin/env bash
# install-vultr.sh — one-shot Orbital install on Vultr.
#
# Chains: tofu apply → kubeconfig export → helm install.
#
# Usage:
#   ./deploy/scripts/install-vultr.sh                  # self-hosted K3s (default)
#   ./deploy/scripts/install-vultr.sh --variant=managed-vke
#   ./deploy/scripts/install-vultr.sh --skip-tofu      # helm install only
#
# Assumes:
#   - You've copied terraform.tfvars.example → terraform.tfvars
#     in the chosen variant's directory and filled it in.
#   - Harbor pull-secret creds in env: HARBOR_USERNAME / HARBOR_TOKEN.
#   - APP_KEY etc. in env or you'll be prompted.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

VARIANT="self-hosted-k3s"
SKIP_TOFU=0
NAMESPACE="${NAMESPACE:-orbital}"
RELEASE="${RELEASE:-orbital}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --variant=*) VARIANT="${1#*=}"; shift ;;
        --skip-tofu) SKIP_TOFU=1; shift ;;
        --namespace=*) NAMESPACE="${1#*=}"; shift ;;
        --release=*) RELEASE="${1#*=}"; shift ;;
        --yes|-y) export ASSUME_YES=1; shift ;;
        -h|--help)
            cat <<EOF
install-vultr.sh — Orbital one-shot installer for Vultr

Options:
  --variant=<name>     self-hosted-k3s (default) | managed-vke
  --skip-tofu          Skip 'tofu apply'; only run helm install
  --namespace=<ns>     K8s namespace (default: orbital)
  --release=<name>     Helm release name (default: orbital)
  --yes, -y            Skip confirmation prompts

Required env vars:
  HARBOR_USERNAME      Harbor robot account username (e.g. robot\$<customer>-pull)
  HARBOR_TOKEN         Harbor robot account token

Tofu vars file:
  deploy/terraform/vultr/<variant>/terraform.tfvars
EOF
            exit 0 ;;
        *) log_error "unknown arg: $1"; exit 1 ;;
    esac
done

case "$VARIANT" in
    self-hosted-k3s|managed-vke) ;;
    *) log_error "variant must be self-hosted-k3s or managed-vke"; exit 1 ;;
esac

# --- Preflight ---------------------------------------------------

require_cmd tofu  "https://opentofu.org/docs/intro/install/"
require_cmd helm  "https://helm.sh/docs/intro/install/"
require_cmd kubectl "https://kubernetes.io/docs/tasks/tools/"

REPO=$(repo_root)
TOFU_DIR="${REPO}/deploy/terraform/vultr/${VARIANT}"
CHART_DIR="${REPO}/deploy/helm/orbital"
VALUES_FILE="${CHART_DIR}/values-vultr-${VARIANT##*-}.yaml"

if [[ "$VARIANT" == "self-hosted-k3s" ]]; then
    VALUES_FILE="${CHART_DIR}/values-vultr-k3s.yaml"
elif [[ "$VARIANT" == "managed-vke" ]]; then
    VALUES_FILE="${CHART_DIR}/values-vultr-vke.yaml"
fi

if [[ ! -f "${TOFU_DIR}/terraform.tfvars" ]] && [[ "$SKIP_TOFU" == "0" ]]; then
    log_error "${TOFU_DIR}/terraform.tfvars missing."
    log_error "Copy terraform.tfvars.example and fill it in first."
    exit 1
fi

if [[ -z "${HARBOR_USERNAME:-}" || -z "${HARBOR_TOKEN:-}" ]]; then
    log_error "Set HARBOR_USERNAME + HARBOR_TOKEN in env (Harbor robot creds)"
    exit 1
fi

# --- Stage 1: tofu apply ----------------------------------------

if [[ "$SKIP_TOFU" == "0" ]]; then
    log_info "Running tofu apply against ${TOFU_DIR}"
    pushd "$TOFU_DIR" >/dev/null
    tofu init
    tofu apply -var-file=terraform.tfvars -auto-approve
    popd >/dev/null
    log_ok "tofu apply complete"
fi

# --- Stage 2: kubeconfig export ---------------------------------

pushd "$TOFU_DIR" >/dev/null
if [[ "$VARIANT" == "managed-vke" ]]; then
    KUBECONFIG_PATH=$(tofu output -raw kubeconfig_path)
else
    # Self-hosted-K3s — kubeconfig lives on the first server. We
    # SSH-fetch it via the OpenTofu-generated key + rewrite the
    # 127.0.0.1 server URL to the public IP.
    SSH_KEY=$(tofu output -raw ssh_private_key_path)
    SERVER_IP=$(tofu output -json k3s_server_ips | jq -r '.[0]')
    KUBECONFIG_PATH="${REPO}/deploy/terraform/vultr/${VARIANT}/.kube/kubeconfig"
    mkdir -p "$(dirname "$KUBECONFIG_PATH")"
    log_info "Fetching kubeconfig from ${SERVER_IP}"
    ssh -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new "orbital@${SERVER_IP}" \
        "sudo cat /etc/rancher/k3s/k3s.yaml" \
        | sed "s/127.0.0.1/${SERVER_IP}/g" \
        > "$KUBECONFIG_PATH"
    chmod 600 "$KUBECONFIG_PATH"
fi
popd >/dev/null

export KUBECONFIG="$KUBECONFIG_PATH"
log_ok "kubeconfig: $KUBECONFIG_PATH"

# --- Stage 2b: VKE-only — install ingress-nginx + cert-manager --

if [[ "$VARIANT" == "managed-vke" ]]; then
    log_info "Installing ingress-nginx + cert-manager (VKE doesn't ship them)"
    helm repo add ingress-nginx https://kubernetes.github.io/ingress-nginx 2>/dev/null || true
    helm repo add jetstack https://charts.jetstack.io 2>/dev/null || true
    helm repo update

    helm upgrade --install ingress-nginx ingress-nginx/ingress-nginx \
        --namespace ingress-nginx --create-namespace \
        --set controller.service.type=LoadBalancer \
        --wait

    helm upgrade --install cert-manager jetstack/cert-manager \
        --namespace cert-manager --create-namespace \
        --set crds.enabled=true \
        --wait
fi

# --- Stage 3: namespace + secrets -------------------------------

kubectl create namespace "$NAMESPACE" --dry-run=client -o yaml | kubectl apply -f -

log_info "Creating Harbor pull secret"
kubectl --namespace "$NAMESPACE" create secret docker-registry orbital-registry-creds \
    --docker-server="cr.calltheory.com" \
    --docker-username="$HARBOR_USERNAME" \
    --docker-password="$HARBOR_TOKEN" \
    --dry-run=client -o yaml | kubectl apply -f -

log_info "Creating app-secrets (placeholders if env vars unset)"
kubectl --namespace "$NAMESPACE" create secret generic "${RELEASE}-orbital-app-secrets" \
    --from-literal=APP_KEY="${APP_KEY:-base64:$(openssl rand -base64 32)}" \
    --from-literal=DB_PASSWORD="${DB_PASSWORD:-$(openssl rand -hex 16)}" \
    --from-literal=REDIS_PASSWORD="${REDIS_PASSWORD:-$(openssl rand -hex 16)}" \
    --from-literal=AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-orbital-dev-key}" \
    --from-literal=AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-$(openssl rand -hex 32)}" \
    --from-literal=LIVEKIT_API_KEY="${LIVEKIT_API_KEY:-APIorbital$(openssl rand -hex 4)}" \
    --from-literal=LIVEKIT_API_SECRET="${LIVEKIT_API_SECRET:-$(openssl rand -hex 32)}" \
    --from-literal=ASTERISK_AMI_SECRET="${ASTERISK_AMI_SECRET:-$(openssl rand -hex 16)}" \
    --from-literal=ANTHROPIC_API_KEY="${ANTHROPIC_API_KEY:-}" \
    --from-literal=OPENAI_API_KEY="${OPENAI_API_KEY:-}" \
    --from-literal=ELEVENLABS_API_KEY="${ELEVENLABS_API_KEY:-}" \
    --dry-run=client -o yaml | kubectl apply -f -

# --- Stage 4: helm install --------------------------------------

log_info "helm upgrade --install ${RELEASE}"
helm upgrade --install "$RELEASE" "$CHART_DIR" \
    --namespace "$NAMESPACE" \
    -f "$VALUES_FILE" \
    --wait \
    --timeout 10m

log_ok "Orbital installed. Run:"
echo "  kubectl --namespace ${NAMESPACE} get pods"
echo "  helm --namespace ${NAMESPACE} status ${RELEASE}"
echo
echo "Edge VMs:"
pushd "$TOFU_DIR" >/dev/null
tofu output edge_ips 2>/dev/null || true
echo
echo "Edge VIP (point sip.<your-domain> at this):"
tofu output edge_vip 2>/dev/null || true
popd >/dev/null
