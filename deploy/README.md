# Deploying Orbital

Orbital is two layers of infrastructure:

1. **The edge** — two VMs running Kamailio (SIP signaling) + rtpengine (media relay). External SIP trunks and operator softphones land here. Active/active via shared Valkey state.
2. **The cluster** — a Kubernetes cluster (K3s by default, or a managed offering like Vultr's VKE) running the application stack: Laravel + Horizon + Reverb, Asterisk, LiveKit, LiveKit-SIP, the Python agent worker.

This directory has everything you need to stand both layers up on the cloud (or on-prem) of your choice.

## Pick your path

| You want…                                  | Go to                                          |
|--------------------------------------------|------------------------------------------------|
| Vultr (self-hosted K3s, default)           | [`terraform/vultr/self-hosted-k3s/`](./terraform/vultr/self-hosted-k3s/) |
| Vultr Kubernetes Engine (managed)          | [`terraform/vultr/managed-vke/`](./terraform/vultr/managed-vke/) |
| On-prem with VMs you already have          | [`terraform/onprem/k3s/`](./terraform/onprem/k3s/) |
| Iterate the Helm chart locally on a laptop | [`local/`](./local/) |
| AWS / Azure                                | deferred to follow-up — see [`terraform/aws/`](./terraform/aws/) and [`terraform/azure/`](./terraform/azure/) |

## What lives where

```
deploy/
├── helm/orbital/        # the K8s chart (provider-agnostic)
├── terraform/           # per-provider IaC (you pick one)
│   ├── modules/         # shared abstractions across providers
│   ├── vultr/{self-hosted-k3s, managed-vke}/
│   ├── onprem/{k3s, proxmox, libvirt}/
│   └── aws/, azure/     # placeholders for future providers
├── ansible/             # post-provision config (rtpengine, kamailio, K3s)
├── cloud-init/          # bootstrap stubs that pull + run the Ansible
├── local/               # k3d helper scripts for chart iteration
└── scripts/             # one-shot install wrappers
```

## IaC tool

All `.tf` files in `terraform/` are **OpenTofu**, not HashiCorp Terraform. Same HCL syntax, same provider ecosystem (Vultr, libvirt, proxmox, all the major ones). The CLI is `tofu` instead of `terraform`. The directory keeps the conventional name `terraform/` for discoverability.

Install OpenTofu: <https://opentofu.org/docs/intro/install/>

## How it fits together

For a typical Vultr install:

1. **OpenTofu stands up infrastructure.**
   - Two edge VMs with cloud-init userdata that pulls + runs Ansible against themselves
   - A K3s cluster (or VKE if you picked managed)
   - Networking, block storage, reserved IPs
2. **Ansible (running on each VM via cloud-init) installs Kamailio + rtpengine + certs.** Idempotent — re-run any time to reapply config.
3. **Helm deploys the application stack** into the K8s cluster.
4. **You point your DNS** at the edge VMs' floating IP and the cluster's load balancer.
5. **Customers connect** SIP trunks to the edge SIP IP; operators load the admin panel from the cluster's HTTPS endpoint.

The edge and the cluster talk to each other over the public-internet-or-VPC-peer IPs OpenTofu outputs — no shared private network required.

## Image registry

Orbital images live in a private Harbor registry. Customers receive per-customer Harbor robot accounts and create a `kubectl create secret docker-registry orbital-registry-creds ...` before `helm install`. See [`helm/README.md`](./helm/README.md) for the onboarding flow.

## State backends

OpenTofu state must NOT live in this repo. Each provider's README documents the recommended backend (Vultr Object Storage for Vultr installs, S3 / Azure Blob for cloud installs, local file for on-prem when nothing else makes sense).

## Workflow once everything's up

- **Iterate on the application:** push code, CI builds + tags an image, `helm upgrade --set global.image.tag=<new-tag>` rolls the cluster.
- **Iterate on edge config (rtpengine, kamailio):** push to the repo, re-run the Ansible playbook against the edge VMs (or wait for cloud-init's `ansible-pull` on next reboot — both are valid).
- **Iterate on infrastructure shape:** edit the `.tf` files, `tofu plan` + `tofu apply` for the change.
- **Drain a node for maintenance:** Filament admin → Failover Central → drain the rtpengine or Asterisk you want to service. The Filament UI is the single source of truth for runtime drain state across both layers.
