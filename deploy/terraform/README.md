# `terraform/` — provider-agnostic IaC

Every `.tf` file in this directory is **OpenTofu**, not HashiCorp Terraform. Same HCL syntax, same provider ecosystem, same workflow — just the OSS fork. The CLI is `tofu`. Directory keeps the conventional name `terraform/` for discoverability.

Install OpenTofu: <https://opentofu.org/docs/intro/install/>

## Provider matrix (v1)

| Path                                       | What it does                                                      |
|--------------------------------------------|-------------------------------------------------------------------|
| `vultr/self-hosted-k3s/`                   | 2 edge VMs + N K3s servers + N agents + VPC + reserved IP. **Default Vultr path.** |
| `vultr/managed-vke/`                       | 2 edge VMs + Vultr Kubernetes Engine (managed control plane)      |
| `onprem/k3s/`                              | Bring-your-own-VMs — generates Ansible inventory + runs playbooks |
| `onprem/proxmox/`                          | (placeholder) Proxmox VE provisioning                             |
| `onprem/libvirt/`                          | (placeholder) KVM/libvirt provisioning                            |
| `aws/`                                     | (deferred to follow-up plan)                                      |
| `azure/`                                   | (deferred to follow-up plan)                                      |

## Shared modules

| Module                  | Description                                                  |
|-------------------------|--------------------------------------------------------------|
| `modules/edge-vm-pair/` | Variable + output contract every edge-VM impl honors         |
| `modules/k8s-cluster/`  | Variable + output contract every K8s-cluster impl honors     |

These modules don't create resources themselves — each provider implementation supplies its own resources matching the contract. The contract lives in `interface.tf` / `variables.tf` / `outputs.tf` per module.

## State backend

Terraform / OpenTofu state must NOT live in this repo. Recommended per provider:

| Provider | Backend                                                                       |
|----------|-------------------------------------------------------------------------------|
| Vultr    | Vultr Object Storage (S3-compatible)                                          |
| AWS      | S3 + DynamoDB lock                                                            |
| Azure    | Azure Blob Storage                                                            |
| On-prem  | Local file in your secrets dir, or self-hosted MinIO / Garage with TLS        |

Each provider's README has a copy-paste backend configuration block.

## Workflow (every provider)

```bash
cd deploy/terraform/<provider>/<variant>
cp terraform.tfvars.example terraform.tfvars
${EDITOR:-vim} terraform.tfvars

tofu init
tofu plan -var-file=terraform.tfvars
tofu apply -var-file=terraform.tfvars

# Capture outputs into the install script's hand-off:
tofu output -json
```

Then chain into `helm install` via the matching `deploy/scripts/install-<provider>.sh` wrapper.

## Adding a new provider

1. Create `terraform/<provider>/<variant>/{versions,variables,main,outputs}.tf` matching the `modules/edge-vm-pair/` + `modules/k8s-cluster/` interface contracts.
2. Add a `terraform.tfvars.example` with safe defaults.
3. Write a `README.md` explaining the workflow + state backend.
4. Add a matching values file in `helm/orbital/values-<provider>-<variant>.yaml`.
5. (Optional) Add a `scripts/install-<provider>.sh` wrapper.

The `aws/` and `azure/` placeholders show the layout; pick one up when there's a customer who needs it.
