# Vultr self-hosted K3s — top-level entrypoint.
#
# Provisions:
#   1. A VPC for cluster-internal networking
#   2. N edge VMs (kamailio + rtpengine) with cloud-init that
#      ansible-pulls the edge-bootstrap playbook
#   3. K3s server + agent VMs with cloud-init that ansible-pulls
#      the k3s-bootstrap playbook
#   4. Reserved IPs for the SIP edge VIP (one per region for VRRP)
#   5. SSH keypair (generated at apply time, `local-exec`-saved)
#
# After `tofu apply` finishes, the operator runs:
#   ./scripts/install-vultr.sh
# which reads the kubeconfig output + helm-installs Orbital.

provider "vultr" {
  api_key     = var.vultr_api_key
  rate_limit  = 700
  retry_limit = 3
}

# --- SSH keypair --------------------------------------------------
# Generated at apply time; private key written to disk for Ansible
# inventory. This is the operator's "machine ID" for SSH access.

resource "tls_private_key" "ssh" {
  algorithm = "ED25519"
}

resource "local_sensitive_file" "ssh_private_key" {
  filename        = "${path.module}/.ssh/orbital-${var.cluster_name}.key"
  content         = tls_private_key.ssh.private_key_openssh
  file_permission = "0600"
}

resource "vultr_ssh_key" "orbital" {
  name    = "${var.cluster_name}-deploy"
  ssh_key = tls_private_key.ssh.public_key_openssh
}

# Operator's pre-existing SSH key, if provided
resource "vultr_ssh_key" "operator" {
  count   = var.ssh_public_key == "" ? 0 : 1
  name    = "${var.cluster_name}-operator"
  ssh_key = var.ssh_public_key
}

# --- VPC ----------------------------------------------------------
# Edge VMs + K3s nodes share one VPC so DMQ peering, K3s flannel,
# and intra-cluster API traffic stay off the public internet.

resource "vultr_vpc" "main" {
  description    = "${var.cluster_name}-vpc"
  region         = var.region
  v4_subnet      = "10.42.0.0"
  v4_subnet_mask = 16
}

# --- K3s cluster token --------------------------------------------
# Shared by every server + agent. Generated once per cluster.

resource "random_password" "k3s_token" {
  length  = 64
  special = false
}

# --- K3s servers --------------------------------------------------
# First server bootstraps with --cluster-init; subsequent servers
# join via --server URL. The cloud-init template handles both via
# the `k3s_first_server` flag.

resource "vultr_instance" "k3s_server" {
  count             = var.k3s_server_count
  plan              = var.k3s_server_size
  region            = var.region
  os_id             = 1743                             # Ubuntu 22.04 LTS
  label             = "${var.cluster_name}-k3s-server-${count.index + 1}"
  hostname          = "${var.cluster_name}-k3s-server-${count.index + 1}"
  enable_ipv6       = true
  vpc_ids           = [vultr_vpc.main.id]
  ssh_key_ids       = compact([vultr_ssh_key.orbital.id, try(vultr_ssh_key.operator[0].id, "")])
  backups           = "disabled"
  ddos_protection   = false
  activation_email  = false
  user_data         = templatefile("${path.module}/../../../cloud-init/k3s-server.tmpl.yaml", {
    ansible_repo_url      = var.ansible_repo_url
    ansible_branch        = var.ansible_branch
    admin_authorized_keys = jsonencode(concat(var.admin_authorized_keys, [tls_private_key.ssh.public_key_openssh]))
    k3s_role              = "server"
    k3s_first_server      = count.index == 0 ? "true" : "false"
    k3s_token             = random_password.k3s_token.result
    # Subsequent servers join the first; the first bootstraps so its URL is irrelevant.
    k3s_server_url        = count.index == 0 ? "" : "https://${vultr_instance.k3s_server[0].internal_ip}:6443"
  })

  # Force ordered create — first server must be up + bootstrapped
  # before the join URL is known for subsequent servers.
  depends_on = [vultr_vpc.main]
}

# --- K3s agents ---------------------------------------------------

resource "vultr_instance" "k3s_agent" {
  count             = var.k3s_agent_count
  plan              = var.k3s_agent_size
  region            = var.region
  os_id             = 1743
  label             = "${var.cluster_name}-k3s-agent-${count.index + 1}"
  hostname          = "${var.cluster_name}-k3s-agent-${count.index + 1}"
  enable_ipv6       = true
  vpc_ids           = [vultr_vpc.main.id]
  ssh_key_ids       = compact([vultr_ssh_key.orbital.id, try(vultr_ssh_key.operator[0].id, "")])
  backups           = "disabled"
  activation_email  = false
  user_data         = templatefile("${path.module}/../../../cloud-init/k3s-server.tmpl.yaml", {
    ansible_repo_url      = var.ansible_repo_url
    ansible_branch        = var.ansible_branch
    admin_authorized_keys = jsonencode(concat(var.admin_authorized_keys, [tls_private_key.ssh.public_key_openssh]))
    k3s_role              = "agent"
    k3s_first_server      = "false"
    k3s_token             = random_password.k3s_token.result
    k3s_server_url        = "https://${vultr_instance.k3s_server[0].internal_ip}:6443"
  })

  depends_on = [vultr_instance.k3s_server]
}

# --- Edge VMs (kamailio + rtpengine) ------------------------------

resource "vultr_instance" "edge" {
  count             = var.edge_instance_count
  plan              = var.edge_instance_size
  region            = var.region
  os_id             = 1743
  label             = "${var.cluster_name}-edge-${count.index + 1}"
  hostname          = "${var.cluster_name}-edge-${count.index + 1}"
  enable_ipv6       = true
  vpc_ids           = [vultr_vpc.main.id]
  ssh_key_ids       = compact([vultr_ssh_key.orbital.id, try(vultr_ssh_key.operator[0].id, "")])
  backups           = "disabled"
  activation_email  = false

  user_data = templatefile("${path.module}/../../../cloud-init/edge.tmpl.yaml", {
    ansible_repo_url      = var.ansible_repo_url
    ansible_branch        = var.ansible_branch
    admin_authorized_keys = jsonencode(concat(var.admin_authorized_keys, [tls_private_key.ssh.public_key_openssh]))
    certs_acme_email      = var.tls_email
    certs_acme_domain     = var.tls_domain
    certs_acme_webhook_url   = var.tls_webhook_url
    certs_acme_webhook_token = var.tls_webhook_token
    # K8s Service IP for kamailio's dispatcher list. Resolved via
    # the cluster's DNS once helm install lands the headless
    # Service. For Phase 1 we point at every K3s agent so the
    # NodePort path works pre-ingress.
    kamailio_dispatcher_targets_yaml = jsonencode([
      for ip in vultr_instance.k3s_agent[*].main_ip : { host = ip, port = 30060 }
    ])
    kamailio_dmq_peers_yaml = jsonencode([
      for ip in [for i, _ in range(var.edge_instance_count) : i == count.index ? "" : "" /* peer hostnames filled at apply via local-exec; placeholder for now */] : ip if ip != ""
    ])
  })

  depends_on = [vultr_instance.k3s_agent]
}

# --- Edge VIP — a Vultr reserved IP attached to edge-1 by default.
# On failover the operator reassigns it via the Vultr API; full VRRP
# auto-failover lands as a follow-up (needs floating-IP API auth from
# inside the VM, which Vultr supports but isn't in this v1).

resource "vultr_reserved_ip" "edge_vip" {
  region    = var.region
  ip_type   = "v4"
  label     = "${var.cluster_name}-edge-vip"
  instance_id = vultr_instance.edge[0].id
}
