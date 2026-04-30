# On-prem K3s — bring-your-own-VMs entrypoint.
#
# Operator already has VMs provisioned (bare metal, Proxmox, ESXi,
# whatever) and SSH-reachable. We don't create any infra here —
# this OpenTofu module is a thin shim that drives Ansible with
# the inventory the operator hands in.

variable "edge_vm_ips" {
  description = "List of edge VM public IPs (kamailio + rtpengine will be installed here)"
  type        = list(string)
}

variable "k3s_server_ips" {
  description = "List of K3s server (control-plane) IPs. The first one bootstraps."
  type        = list(string)
}

variable "k3s_agent_ips" {
  description = "List of K3s agent (worker) IPs"
  type        = list(string)
  default     = []
}

variable "ssh_user" {
  description = "SSH user for Ansible (must have passwordless sudo)"
  type        = string
  default     = "ubuntu"
}

variable "ssh_private_key_path" {
  description = "Path to the SSH private key Ansible authenticates with"
  type        = string
}

variable "admin_authorized_keys" {
  description = "Additional SSH keys provisioned to the orbital admin user"
  type        = list(string)
  default     = []
}

# --- TLS ---
variable "tls_email" {
  type        = string
  description = "ACME registration email"
}

variable "tls_domain" {
  type        = string
  description = "SIP edge hostname"
}

variable "tls_webhook_url" {
  type        = string
  description = "Laravel TLS webhook URL"
}

variable "tls_webhook_token" {
  type        = string
  description = "Webhook shared secret"
  sensitive   = true
}

# --- Repo source ---
variable "ansible_repo_url" {
  type        = string
  description = "Git URL to clone the playbooks from"
}

variable "ansible_branch" {
  type        = string
  default     = "main"
}

variable "playbook_dir" {
  description = "Path to deploy/ansible relative to this module's working dir"
  type        = string
  default     = "../../../ansible"
}
