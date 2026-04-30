# Vultr self-hosted K3s — `tofu apply` walkthrough

This entrypoint provisions a complete Orbital deployment on Vultr:

- **2 edge VMs** running Kamailio + rtpengine (active/active via DMQ)
- **N K3s server + agent VMs** (1+2 = small dev; 3+3 = HA)
- **A VPC** for cluster-internal traffic
- **A reserved IP** for the SIP edge VIP — point your DNS here
- **An auto-generated SSH key** OpenTofu uses for Ansible inventory access

After `tofu apply` succeeds, run `../../scripts/install-vultr.sh` from the repo root to chain into `helm install`.

## Prerequisites

- [OpenTofu](https://opentofu.org/docs/intro/install/) ≥ 1.7
- A Vultr API key with full access (https://my.vultr.com/settings/#settingsapi)
- A DNS zone you control (Cloudflare / Vultr DNS / Route53 / etc.) — you'll point `sip.<your-domain>` at the edge VIP and `orbital.<your-domain>` at the cluster ingress LB

## State backend

Terraform / OpenTofu state must NOT live in the repo. Recommended for Vultr:

```hcl
# backend.tf — create this once, never commit
terraform {
  backend "s3" {
    endpoint = "https://ewr1.vultrobjects.com"   # Vultr Object Storage S3 API
    bucket   = "your-tofu-state"
    key      = "orbital-prod/vultr-self-hosted-k3s.tfstate"
    region   = "us-east-1"   # any value — Vultr ignores it but the S3 SDK requires one
    skip_credentials_validation = true
    skip_region_validation      = true
    skip_metadata_api_check     = true
  }
}
```

You'll need to create the bucket first via the Vultr console (chicken-and-egg — it can't be in this OpenTofu).

## Workflow

```bash
# 1. From this directory:
cp terraform.tfvars.example terraform.tfvars
${EDITOR:-vim} terraform.tfvars

# 2. Initialize providers:
tofu init

# 3. Preview:
tofu plan -var-file=terraform.tfvars

# 4. Apply:
tofu apply -var-file=terraform.tfvars
# Approval is interactive; this provisions ~5-10 VMs and takes ~3-5 min.

# 5. Capture outputs:
tofu output -json > outputs.json

# 6. Point DNS:
#    A    sip.<your-domain>    → tofu output edge_vip
#    A    orbital.<your-domain> → tofu output ingress_lb_hostname (after helm install)

# 7. From the repo root, run the chained install:
cd ../../../..
./deploy/scripts/install-vultr.sh
```

## What `tofu apply` does NOT do

- **Helm install** — the OpenTofu module provisions infra; the
  app stack lands via `helm install` triggered from `install-vultr.sh`.
  Keeps the OpenTofu module focused (state only carries cloud
  resources, not chart releases).
- **DNS records** — automated DNS via the Vultr DNS provider is a
  follow-up; for now, manual A-record creation in the Vultr
  console / your registrar.
- **Cert issuance** — acme.sh on each edge VM handles its own cert
  via the certs-acme Ansible role triggered by cloud-init. No
  OpenTofu-side cert lifecycle.

## Tearing down

```bash
tofu destroy -var-file=terraform.tfvars
```

Removes every Vultr resource cleanly — VMs, VPC, reserved IP, SSH
keys. State bucket survives (intentional — keeps the audit trail).
