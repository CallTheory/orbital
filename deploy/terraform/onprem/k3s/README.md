# On-prem K3s — bring your own VMs

For deployments where you've already provisioned VMs (bare metal,
Proxmox, ESXi, OpenStack, an existing colocation rack, etc.) and
want Orbital running on top of them. No cloud-side resource
creation here — this OpenTofu module is a thin Ansible driver.

## Prerequisites

- N+M VMs reachable via SSH:
  - 2 edge VMs for kamailio + rtpengine
  - 1 K3s server (or 3 for HA)
  - 2+ K3s agents
- All VMs run a recent Ubuntu / Debian (22.04+, 12+)
- A user with passwordless `sudo` (default: `ubuntu`); SSH key auth set up
- DNS for `sip.<your-domain>` pointed at the edge VIP (you'll
  manage failover via VRRP / DNS RR / your loadbalancer)
- DNS for `orbital.<your-domain>` pointed at the K3s ingress LB

## Workflow

```bash
# Install OpenTofu + Ansible on the controller (your laptop / a CI runner)
# Provision VMs out-of-band with whatever your toolchain is
# (Proxmox templates, MAAS, kickstart, Foreman — whatever)

cp terraform.tfvars.example terraform.tfvars
${EDITOR:-vim} terraform.tfvars
# Set the IPs of every VM you provisioned

tofu init
tofu plan -var-file=terraform.tfvars
tofu apply -var-file=terraform.tfvars
# This generates the Ansible inventory + group_vars + runs both
# playbooks against the VMs. Total wall time: ~5-10 minutes
# depending on package mirror speed.

# Copy the kubeconfig to your local kube context:
export KUBECONFIG=$(tofu output -raw kubeconfig_path)
kubectl get nodes

# helm install the chart:
cd ../../../..
./deploy/scripts/install-onprem.sh
```

## What this module does

1. Generates `deploy/ansible/inventory/onprem.ini` from your VM IPs
2. Writes group_vars files for `edge_vms`, `k3s_servers`, `k3s_agents`
3. Runs `playbooks/edge-bootstrap.yml` against `edge_vms`:
   - Common hardening (UFW, fail2ban, journald limits)
   - acme.sh + cert issuance
   - rtpengine install + config
   - kamailio install + config (TLS, WSS, DMQ, dispatcher)
4. Runs `playbooks/k3s-bootstrap.yml` against the K3s nodes:
   - First server bootstraps with `--cluster-init`
   - Additional servers join via `--server <url>`
   - Agents join via `K3S_URL`
5. Persists the kubeconfig from the first server back to the
   controller so you can run `helm install` against the cluster

## Re-running

`tofu apply` is idempotent. Re-run it to:
- Pick up Ansible playbook changes (the playbook files are in this
  repo; new pulls re-render the inventory hash and re-trigger
  ansible-playbook)
- Add VMs (extend `edge_vm_ips` / `k3s_agent_ips` and re-apply)

## State backend

Recommended for on-prem: a local file in your `deploy/secrets/` dir
(NOT in the repo) or a self-hosted object-store backend (MinIO,
Garage, etc.). Avoid plain HTTP backends without TLS.

## Tearing down

`tofu destroy` removes the inventory + group_vars files and stops
re-running the playbooks. **It does NOT remove the cluster from
your VMs** — that's intentional, since the VMs survived the
"destroy" by definition. To uninstall:

```bash
ssh ubuntu@<server> sudo /usr/local/bin/k3s-uninstall.sh
ssh ubuntu@<agent>  sudo /usr/local/bin/k3s-agent-uninstall.sh
```
