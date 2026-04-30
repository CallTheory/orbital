# `ansible/` — playbooks + roles for edge VMs and K3s nodes

Two playbooks; five roles; one inventory shape.

## Playbooks

| Playbook                          | Targets        | What it installs                                       |
|-----------------------------------|----------------|--------------------------------------------------------|
| `playbooks/edge-bootstrap.yml`    | `edge_vms`     | common + certs-acme + rtpengine + kamailio             |
| `playbooks/k3s-bootstrap.yml`     | `k3s_servers` + `k3s_agents` | common + k3s (server bootstrap or agent join) |

## Roles

| Role          | Purpose                                                                  |
|---------------|--------------------------------------------------------------------------|
| `common`      | UFW + fail2ban + journald limits + admin user + base packages            |
| `certs-acme`  | acme.sh client + LE cert issuance + reload-hook → Laravel TLS webhook    |
| `rtpengine`   | ngcp-rtpengine-daemon + recording-daemon (Sipwise repo); systemd units   |
| `kamailio`    | kamailio + every module Orbital needs (rtpengine, dmq, tls, websocket)   |
| `k3s`         | K3s install via `get.k3s.io`; server-bootstrap or agent-join via vars    |

Every role is idempotent — re-run any time to re-apply config without dropping live traffic.

## Inventory shape

Two paths to populate the inventory:

1. **Cloud (Vultr / AWS / Azure)**: OpenTofu's cloud-init stub bakes the inventory + group_vars onto each VM at create time. The VM runs `ansible-pull` against itself; no Ansible controller needed.
2. **On-prem**: OpenTofu's `onprem/k3s/` shim writes `inventory/onprem.ini` + `inventory/group_vars/*.yml` from the IPs you hand in via `terraform.tfvars`, then `local-exec` runs `ansible-playbook` against them.

Manual override: copy `inventory/hosts.example.ini` → `inventory/hosts.ini` (gitignored) and fill in.

## Re-running playbooks (day-2 ops)

After the OpenTofu module + initial deploy, you can re-run playbooks against the inventory any time:

```bash
cd deploy/ansible

# Pick up new edge config (e.g. updated dispatcher.list, new SAN on TLS cert)
ansible-playbook -i inventory/hosts.ini playbooks/edge-bootstrap.yml

# Add a new K3s agent (after extending k3s_agents in inventory)
ansible-playbook -i inventory/hosts.ini playbooks/k3s-bootstrap.yml --limit k3s_agents
```

cloud-init's `ansible-pull` re-runs on every reboot, so reboots also re-apply the latest playbook from the repo (unless you've pinned `ansible_branch` to a specific tag).

## Required variables

Every playbook needs these in group_vars (cloud paths set them via cloud-init; on-prem paths via the OpenTofu shim):

| Group     | Variable                          | Description                                       |
|-----------|-----------------------------------|---------------------------------------------------|
| edge_vms  | `common_admin_authorized_keys`    | SSH keys for the orbital admin user               |
| edge_vms  | `certs_acme_email`                | ACME registration email                           |
| edge_vms  | `certs_acme_domain`               | SIP edge hostname                                 |
| edge_vms  | `certs_acme_webhook_url`          | Laravel TLS-renewal webhook URL                   |
| edge_vms  | `certs_acme_webhook_token`        | Webhook shared secret                             |
| edge_vms  | `kamailio_dispatcher_targets`     | List of K8s Asterisk endpoints                    |
| edge_vms  | `kamailio_dmq_peers`              | List of peer edge hostnames for active/active     |
| k3s_*     | `k3s_token`                       | Shared cluster join token (Vault / TF random)     |
| k3s_*     | `k3s_server_url`                  | https://<first-server-ip>:6443                    |

## Connection requirements

- Passwordless `sudo` for the `ansible_user` (default `ubuntu`)
- SSH key auth (`ansible_ssh_private_key_file`)
- Firewall allows SSH from the Ansible controller (your laptop / CI runner)

## Skipping K3s on managed K8s

The `k3s-bootstrap.yml` playbook only runs on self-hosted-K3s installs. Managed K8s entrypoints (VKE, EKS, AKS) skip it entirely — the cluster is provisioned by the cloud's API, not by Ansible.
