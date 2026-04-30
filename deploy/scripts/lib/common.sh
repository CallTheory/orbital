#!/usr/bin/env bash
# Shared helpers for deploy/scripts/*.sh — sourced, not executed.

set -euo pipefail

# Output helpers
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info()  { echo -e "${BLUE}==>${NC} $*"; }
log_ok()    { echo -e "${GREEN}OK${NC}  $*"; }
log_warn()  { echo -e "${YELLOW}WARN${NC} $*" >&2; }
log_error() { echo -e "${RED}ERR${NC}  $*" >&2; }

# Bail with a message if a required tool isn't on PATH.
require_cmd() {
    local cmd="$1"
    local install_hint="${2:-}"
    if ! command -v "$cmd" >/dev/null 2>&1; then
        log_error "$cmd not on PATH."
        if [[ -n "$install_hint" ]]; then
            log_error "Install: $install_hint"
        fi
        exit 1
    fi
}

# Locate the repo root from any deploy/ path.
repo_root() {
    git rev-parse --show-toplevel 2>/dev/null || \
        (cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
}

# Confirm the operator wants to proceed; bypass with --yes.
confirm() {
    local prompt="$1"
    if [[ "${ASSUME_YES:-0}" == "1" ]]; then
        return 0
    fi
    read -r -p "$prompt [y/N] " response
    [[ "$response" =~ ^[Yy]$ ]]
}
