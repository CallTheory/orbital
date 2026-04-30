# OpenTofu — Vultr self-hosted K3s install.
#
# Pinned provider versions. Bump intentionally; pinning avoids
# surprise infrastructure changes from upstream provider updates
# between deploys.

terraform {
  required_version = ">= 1.7.0"

  required_providers {
    vultr = {
      source  = "vultr/vultr"
      version = "~> 2.21"
    }
    tls = {
      source  = "hashicorp/tls"
      version = "~> 4.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
    local = {
      source  = "hashicorp/local"
      version = "~> 2.5"
    }
  }
}
