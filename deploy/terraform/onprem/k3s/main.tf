# On-prem K3s — bring-your-own-VMs.
#
# Pure Ansible-driver. We don't create any cloud resources;
# we generate an inventory file from the IPs the operator hands
# in, then `local-exec` ansible-playbook to install the edge VM
# stack + the K3s cluster on those VMs.
#
# This is the right entrypoint for:
#   - bare-metal Linux servers
#   - VMs from a hypervisor we don't have a OpenTofu provider for
#     (Hyper-V, OpenStack, etc.)
#   - "I already have VMs provisioned via my own toolchain"

resource "random_password" "k3s_token" {
  length  = 64
  special = false
}

# --- Render the Ansible inventory ----------------------------------

resource "local_file" "inventory" {
  filename        = "${var.playbook_dir}/inventory/onprem.ini"
  file_permission = "0600"
  content = templatefile("${path.module}/inventory.tmpl.ini", {
    edge_vm_ips         = var.edge_vm_ips
    k3s_server_ips      = var.k3s_server_ips
    k3s_agent_ips       = var.k3s_agent_ips
    ssh_user            = var.ssh_user
    ssh_private_key_path = var.ssh_private_key_path
  })
}

resource "local_file" "edge_group_vars" {
  filename        = "${var.playbook_dir}/inventory/group_vars/edge_vms.yml"
  file_permission = "0600"
  content = yamlencode({
    common_admin_authorized_keys = var.admin_authorized_keys
    certs_acme_email             = var.tls_email
    certs_acme_domain            = var.tls_domain
    certs_acme_webhook_url       = var.tls_webhook_url
    certs_acme_webhook_token     = var.tls_webhook_token
    kamailio_dispatcher_targets  = [
      for ip in var.k3s_agent_ips : { host = ip, port = 30060 }
    ]
    kamailio_dmq_peers = var.edge_vm_ips
  })
}

resource "local_file" "k3s_servers_group_vars" {
  filename        = "${var.playbook_dir}/inventory/group_vars/k3s_servers.yml"
  file_permission = "0600"
  content = yamlencode({
    k3s_token      = random_password.k3s_token.result
    k3s_server_url = "https://${var.k3s_server_ips[0]}:6443"
  })
}

resource "local_file" "k3s_agents_group_vars" {
  filename        = "${var.playbook_dir}/inventory/group_vars/k3s_agents.yml"
  file_permission = "0600"
  content = yamlencode({
    k3s_token      = random_password.k3s_token.result
    k3s_server_url = "https://${var.k3s_server_ips[0]}:6443"
  })
}

# --- Run the Ansible playbooks against the inventory --------------
# null_resource + local-exec is the standard pattern for
# "OpenTofu drives Ansible" — re-runs whenever inventory changes.

resource "null_resource" "edge_bootstrap" {
  triggers = {
    inventory_hash = local_file.inventory.content_md5
    edge_vars_hash = local_file.edge_group_vars.content_md5
  }
  provisioner "local-exec" {
    working_dir = var.playbook_dir
    command     = "ansible-playbook -i inventory/onprem.ini playbooks/edge-bootstrap.yml"
  }
  depends_on = [local_file.inventory, local_file.edge_group_vars]
}

resource "null_resource" "k3s_bootstrap" {
  triggers = {
    inventory_hash       = local_file.inventory.content_md5
    server_vars_hash     = local_file.k3s_servers_group_vars.content_md5
    agent_vars_hash      = local_file.k3s_agents_group_vars.content_md5
  }
  provisioner "local-exec" {
    working_dir = var.playbook_dir
    command     = "ansible-playbook -i inventory/onprem.ini playbooks/k3s-bootstrap.yml"
  }
  depends_on = [
    local_file.inventory,
    local_file.k3s_servers_group_vars,
    local_file.k3s_agents_group_vars,
  ]
}
