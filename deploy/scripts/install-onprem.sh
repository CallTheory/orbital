#!/usr/bin/env bash
# install-onprem.sh — one-shot Orbital install on bring-your-own-VMs.
#
# Chains: tofu apply (which runs Ansible against your VMs)
#       → kubeconfig export → helm install.
#
# Assumes:
#   - You've already provisioned VMs (bare metal, Proxmox, ESXi, …)
#     and they're SSH-reachable with passwordless sudo.
#   - terraform.tfvars at deploy/terraform/onprem/k3s/ has the IPs
#     filled in.
#   - Harbor pull-secret creds in env: HARBOR_USERNAME / HARBOR_TOKEN.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

SKIP_TOFU=0
NAMESPACE="${NAMESPACE:-orbital}"
RELEASE="${RELEASE:-orbital}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --skip-tofu) SKIP_TOFU=1; shift ;;
        --namespace=*) NAMESPACE="${1#*=}"; shift ;;
        --release=*) RELEASE="${1#*=}"; shift ;;
        --yes|-y) export ASSUME_YES=1; shift ;;
        -h|--help)
            cat <<EOF
install-onprem.sh — Orbital one-shot installer for bring-your-own-VMs

Options:
  --skip-tofu          Skip 'tofu apply'; only run helm install
  --namespace=<ns>     K8s namespace (default: orbital)
  --release=<name>     Helm release name (default: orbital)
  --yes, -y            Skip confirmation prompts

Required env vars:
  HARBOR_USERNAME      Harbor robot account username
  HARBOR_TOKEN         Harbor robot account token

Tofu vars file:
  deploy/terraform/onprem/k3s/terraform.tfvars
EOF
            exit 0 ;;
        *) log_error "unknown arg: $1"; exit 1 ;;
    esac
done

require_cmd tofu  "https://opentofu.org/docs/intro/install/"
require_cmd helm  "https://helm.sh/docs/intro/install/"
require_cmd kubectl "https://kubernetes.io/docs/tasks/tools/"
require_cmd ansible-playbook "https://docs.ansible.com/ansible/latest/installation_guide/"

REPO=$(repo_root)
TOFU_DIR="${REPO}/deploy/terraform/onprem/k3s"
CHART_DIR="${REPO}/deploy/helm/orbital"
VALUES_FILE="${CHART_DIR}/values-onprem-k3s.yaml"

if [[ ! -f "${TOFU_DIR}/terraform.tfvars" ]] && [[ "$SKIP_TOFU" == "0" ]]; then
    log_error "${TOFU_DIR}/terraform.tfvars missing."
    log_error "Copy terraform.tfvars.example and fill it in first."
    exit 1
fi

if [[ -z "${HARBOR_USERNAME:-}" || -z "${HARBOR_TOKEN:-}" ]]; then
    log_error "Set HARBOR_USERNAME + HARBOR_TOKEN in env (Harbor robot creds)"
    exit 1
fi

# --- Stage 1: tofu apply (drives Ansible against the VMs) ---

if [[ "$SKIP_TOFU" == "0" ]]; then
    log_info "Running tofu apply (this will run Ansible against your VMs)"
    pushd "$TOFU_DIR" >/dev/null
    tofu init
    tofu apply -var-file=terraform.tfvars -auto-approve
    popd >/dev/null
    log_ok "tofu apply complete — VMs configured"
fi

# --- Stage 2: kubeconfig export -----------------------------------
# k3s-bootstrap.yml's post_tasks step writes the kubeconfig to
# deploy/ansible/<inventory_hostname>.kubeconfig on the controller.
# Find it.

KUBECONFIG_PATH=$(find "${REPO}/deploy/ansible" -maxdepth 1 -name "*.kubeconfig" -type f | head -n1)
if [[ -z "$KUBECONFIG_PATH" ]]; then
    log_error "No kubeconfig found at deploy/ansible/*.kubeconfig"
    log_error "Re-run tofu apply, or check the Ansible playbook output."
    exit 1
fi

export KUBECONFIG="$KUBECONFIG_PATH"
log_ok "kubeconfig: $KUBECONFIG_PATH"

# --- Stage 3: namespace + secrets ---------------------------------

kubectl create namespace "$NAMESPACE" --dry-run=client -o yaml | kubectl apply -f -

log_info "Creating Harbor pull secret"
kubectl --namespace "$NAMESPACE" create secret docker-registry orbital-registry-creds \
    --docker-server="cr.calltheory.com" \
    --docker-username="$HARBOR_USERNAME" \
    --docker-password="$HARBOR_TOKEN" \
    --dry-run=client -o yaml | kubectl apply -f -

log_info "Creating app-secrets"
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

# --- Stage 4: helm install ---------------------------------------

log_info "helm upgrade --install ${RELEASE}"
helm upgrade --install "$RELEASE" "$CHART_DIR" \
    --namespace "$NAMESPACE" \
    -f "$VALUES_FILE" \
    --wait \
    --timeout 10m

log_ok "Orbital installed."
echo
echo "Reach the cluster:"
echo "  export KUBECONFIG=${KUBECONFIG_PATH}"
echo "  kubectl --namespace ${NAMESPACE} get pods"
