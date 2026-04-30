# Vultr Kubernetes Engine — managed control plane variant.
#
# VKE handles the K8s control plane; we provision the worker
# node pool via `vultr_kubernetes` and the edge VMs separately
# (kamailio + rtpengine still run as bare VMs since they need
# wide UDP ranges that don't map cleanly to managed K8s).

provider "vultr" {
  api_key     = var.vultr_api_key
  rate_limit  = 700
  retry_limit = 3
}

# --- SSH keypair for edge VMs ------------------------------------

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

resource "vultr_ssh_key" "operator" {
  count   = var.ssh_public_key == "" ? 0 : 1
  name    = "${var.cluster_name}-operator"
  ssh_key = var.ssh_public_key
}

# --- VKE cluster -------------------------------------------------
# Vultr provisions the control plane; we just declare a node pool.

resource "vultr_kubernetes" "cluster" {
  region  = var.region
  label   = var.cluster_name
  version = var.vke_version

  node_pools {
    label     = var.vke_node_pool_label
    plan      = var.vke_node_pool_size
    node_quantity = var.vke_node_pool_count
    auto_scaler   = false
  }
}

# Persist the kubeconfig to disk so the install script can reach it.
resource "local_sensitive_file" "kubeconfig" {
  filename        = "${path.module}/.kube/kubeconfig-${var.cluster_name}"
  content         = base64decode(vultr_kubernetes.cluster.kube_config)
  file_permission = "0600"
}

# --- Edge VMs (same shape as self-hosted-k3s) --------------------

resource "vultr_instance" "edge" {
  count             = var.edge_instance_count
  plan              = var.edge_instance_size
  region            = var.region
  os_id             = 1743                # Ubuntu 22.04 LTS
  label             = "${var.cluster_name}-edge-${count.index + 1}"
  hostname          = "${var.cluster_name}-edge-${count.index + 1}"
  enable_ipv6       = true
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
    # VKE node IPs — kamailio dispatches via the managed K8s API LB.
    # The install script post-helm-install patches in the actual
    # Service ClusterIPs once the chart's up; this initial value
    # routes via NodePort on every VKE worker.
    kamailio_dispatcher_targets_yaml = jsonencode([
      for ip in vultr_kubernetes.cluster.node_pools[0].nodes[*].ip : { host = ip, port = 30060 }
    ])
    kamailio_dmq_peers_yaml = jsonencode([])
  })

  depends_on = [vultr_kubernetes.cluster]
}

# --- Edge VIP ----------------------------------------------------

resource "vultr_reserved_ip" "edge_vip" {
  region      = var.region
  ip_type     = "v4"
  label       = "${var.cluster_name}-edge-vip"
  instance_id = vultr_instance.edge[0].id
}
