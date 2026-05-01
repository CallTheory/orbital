#!/usr/bin/env bash
# build-and-push.sh — build + push Orbital's first-party images.
#
# Builds three images:
#   - laravel       (the Filament admin + API)
#   - asterisk      (the SIP PBX with Orbital config baked in)
#   - agent-worker  (the Python LiveKit worker)
#
# And tags + pushes them to a registry. Supports two modes:
#   1. Push to a real registry (default: cr.calltheory.com)
#   2. --no-push to build locally without pushing
#
# Auth: HARBOR_USERNAME + HARBOR_TOKEN env vars (or --username +
# --password flags). For Harbor robot accounts the username has the
# `robot$` prefix — quote it on the command line.
#
# Tagging: defaults to `git describe --tags --always --dirty` so
# clean tag pushes get e.g. `1.2.3` and dev builds get `<sha>-dirty`.
# Override with --tag.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

REGISTRY="${REGISTRY:-cr.calltheory.com}"
PROJECT="${PROJECT:-orbital}"
TAG="${TAG:-}"
PUSH=1
PLATFORMS="${PLATFORMS:-linux/amd64}"

REPO=$(repo_root)

usage() {
    cat <<EOF
build-and-push.sh — build + tag + push Orbital images

Usage:
  $0 [options]

Options:
  --registry=<url>     Registry to push to (default: cr.calltheory.com)
  --project=<name>     Project / namespace inside the registry (default: orbital)
  --tag=<tag>          Image tag (default: \`git describe --tags --always --dirty\`)
  --no-push            Build only, skip pushing
  --platforms=<list>   Comma-separated buildx platforms (default: linux/amd64)
  --username=<u>       Registry username (or set HARBOR_USERNAME env)
  --password=<p>       Registry password (or set HARBOR_TOKEN env)
  -h, --help           Show this help

Environment:
  REGISTRY, PROJECT, TAG, PLATFORMS — same as flags
  HARBOR_USERNAME, HARBOR_TOKEN     — registry credentials

Examples:
  # Local dev — build, no push
  $0 --no-push --tag=dev

  # Production release on a tagged commit
  HARBOR_USERNAME='robot\$ci' HARBOR_TOKEN=xxx $0

  # Multi-arch (slower, needs buildx with QEMU)
  $0 --platforms=linux/amd64,linux/arm64
EOF
    exit 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --registry=*) REGISTRY="${1#*=}"; shift ;;
        --project=*) PROJECT="${1#*=}"; shift ;;
        --tag=*) TAG="${1#*=}"; shift ;;
        --no-push) PUSH=0; shift ;;
        --platforms=*) PLATFORMS="${1#*=}"; shift ;;
        --username=*) HARBOR_USERNAME="${1#*=}"; shift ;;
        --password=*) HARBOR_TOKEN="${1#*=}"; shift ;;
        -h|--help) usage ;;
        *) log_error "unknown arg: $1"; exit 1 ;;
    esac
done

require_cmd docker "https://docs.docker.com/engine/install/"

# Default tag: `git describe --tags --always --dirty` strips a leading
# `v` so `v1.2.3` becomes `1.2.3` (matches the chart's appVersion shape).
if [[ -z "${TAG}" ]]; then
    if TAG_FROM_GIT=$(cd "$REPO" && git describe --tags --always --dirty 2>/dev/null); then
        TAG="${TAG_FROM_GIT#v}"
    else
        TAG="dev"
    fi
fi

log_info "Registry: $REGISTRY"
log_info "Project:  $PROJECT"
log_info "Tag:      $TAG"
log_info "Push:     $([ "$PUSH" == "1" ] && echo "yes" || echo "NO (build only)")"

# --- Login --------------------------------------------------------

if [[ "$PUSH" == "1" ]]; then
    if [[ -z "${HARBOR_USERNAME:-}" || -z "${HARBOR_TOKEN:-}" ]]; then
        log_error "HARBOR_USERNAME + HARBOR_TOKEN required for push (or use --no-push)."
        log_error "For Harbor robot accounts: the username starts with \`robot\$\`."
        exit 1
    fi
    log_info "docker login $REGISTRY"
    echo "$HARBOR_TOKEN" | docker login "$REGISTRY" -u "$HARBOR_USERNAME" --password-stdin
fi

# --- Build matrix -------------------------------------------------
# image-name : dockerfile : context

build_one() {
    local name="$1"
    local dockerfile="$2"
    local context="$3"
    local image="${REGISTRY}/${PROJECT}/${name}:${TAG}"
    local extra_args=()

    if [[ "$PUSH" == "1" ]]; then
        extra_args+=(--push)
    fi

    log_info "Building $image (context: $context)"
    docker buildx build \
        --file "$dockerfile" \
        --tag "$image" \
        --platform "$PLATFORMS" \
        "${extra_args[@]}" \
        "$context"
    log_ok "$image"
}

cd "$REPO"

# laravel — context is repo root (Dockerfile copies composer.json + app dir)
build_one "laravel" "docker/8.4/Dockerfile" "."

# asterisk — Dockerfile expects `docker/asterisk/` as context (own asset bundle)
build_one "asterisk" "docker/asterisk/Dockerfile" "docker/asterisk"

# agent-worker — production Dockerfile colocated with the Python code
build_one "agent-worker" "agent-worker/Dockerfile" "agent-worker"

# --- Summary ------------------------------------------------------

echo
log_ok "Built three images at tag '$TAG'"
echo "  ${REGISTRY}/${PROJECT}/laravel:${TAG}"
echo "  ${REGISTRY}/${PROJECT}/asterisk:${TAG}"
echo "  ${REGISTRY}/${PROJECT}/agent-worker:${TAG}"

if [[ "$PUSH" == "1" ]]; then
    echo
    log_info "To deploy this tag:"
    echo "  helm upgrade --install orbital helm/orbital \\"
    echo "      -n orbital \\"
    echo "      -f helm/orbital/values-vultr-k3s.yaml \\"
    echo "      --set global.image.registry=${REGISTRY} \\"
    echo "      --set global.image.tag=${TAG}"
fi
