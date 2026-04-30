# `edge-vm-pair` module — provider-agnostic interface

This module defines the **contract** every provider's edge-VM
implementation honors. It does not create resources itself — each
provider directory (`vultr/`, `aws/`, `azure/`, `onprem/proxmox/`,
etc.) supplies its own implementation that matches this variable +
output shape using the cloud's native resource types.

## Variables (inputs)

| Variable                       | Description                                                              |
|--------------------------------|--------------------------------------------------------------------------|
| `instance_count`               | Number of edge VMs (default 2 — active/active)                           |
| `instance_size`                | Per-provider size identifier (e.g. `vc2-2c-4gb` on Vultr)                |
| `region`                       | Cloud region                                                             |
| `ssh_public_key`               | Operator SSH key, written to the `orbital` admin user                    |
| `admin_authorized_keys`        | Additional SSH keys (operators, CI runners)                              |
| `ansible_repo_url`             | Git URL the cloud-init stub `ansible-pull`s from                         |
| `ansible_branch`               | Git branch (default `main`)                                              |
| `tls_email`                    | ACME registration email                                                  |
| `tls_domain`                   | SIP edge hostname (e.g. `sip.orbital.example.com`)                       |
| `tls_webhook_url`              | Laravel TLS-renewal webhook URL                                          |
| `tls_webhook_token`            | Webhook shared secret                                                    |
| `kamailio_dispatcher_targets`  | List of K8s Asterisk endpoints kamailio dispatches calls to              |

## Outputs

| Output                  | Description                                                       |
|-------------------------|-------------------------------------------------------------------|
| `edge_ips`              | Public IPs                                                        |
| `edge_private_ips`      | Private / VPC IPs (used for DMQ peering)                          |
| `edge_hostnames`        | Hostnames (for SSH + Ansible inventory)                           |
| `kamailio_floating_ip`  | Failover IP — VRRP / Vultr reserved IP / DNS round-robin          |
| `ssh_private_key_path`  | Path to the OpenTofu-generated SSH key, used by Ansible           |

## Implementations

| Provider | Path                                            |
|----------|-------------------------------------------------|
| Vultr    | `terraform/vultr/self-hosted-k3s/edge.tf`       |
| Vultr    | `terraform/vultr/managed-vke/edge.tf`           |
| On-prem  | `terraform/onprem/k3s/main.tf` (no resources — operator brings VMs) |
| AWS      | `terraform/aws/...` (deferred)                  |
| Azure    | `terraform/azure/...` (deferred)                |

Per-provider implementations consume the cloud-init stub at
`deploy/cloud-init/edge.tmpl.yaml` — same template, every cloud.
