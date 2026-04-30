# edge-vm-pair — variable contract.
#
# Every provider's `edge-vm-pair` implementation accepts these
# variables. Per-provider modules under `terraform/<provider>/`
# call into a sibling `_edge_vm_pair_<provider>` module that
# implements the same contract using that cloud's native
# resources (vultr_instance, aws_instance, azurerm_linux_virtual_machine).
#
# This module is "interface-only" — it doesn't create resources
# itself. The per-provider entrypoint declares its own resources
# matching this shape, then exposes the same outputs.

variable "instance_count" {
  description = "Number of edge VMs (Phase 1: 2 = active/active)"
  type        = number
  default     = 2

  validation {
    condition     = var.instance_count >= 1 && var.instance_count <= 4
    error_message = "edge VM count must be between 1 (single-node test) and 4 (large active/active mesh)."
  }
}

variable "instance_size" {
  description = "Per-provider instance size identifier (e.g. vc2-2c-4gb on Vultr, t3.medium on AWS)"
  type        = string
}

variable "region" {
  description = "Cloud region for the edge VMs (Vultr ewr / AWS us-east-1 / etc.)"
  type        = string
}

variable "ssh_public_key" {
  description = "Operator SSH public key — provisioned to the orbital admin user via cloud-init"
  type        = string
}

variable "admin_authorized_keys" {
  description = "List of SSH public keys authorized for the orbital admin user"
  type        = list(string)
  default     = []
}

variable "ansible_repo_url" {
  description = "Git repo URL the cloud-init stub `ansible-pull`s from"
  type        = string
}

variable "ansible_branch" {
  description = "Git branch to pull the playbooks from"
  type        = string
  default     = "main"
}

variable "tls_email" {
  description = "Email for ACME (Let's Encrypt) registration"
  type        = string
}

variable "tls_domain" {
  description = "SIP edge domain to issue cert for (e.g. sip.orbital.example.com)"
  type        = string
}

variable "tls_webhook_url" {
  description = "Laravel TLS-renewal webhook URL"
  type        = string
}

variable "tls_webhook_token" {
  description = "Webhook shared secret"
  type        = string
  sensitive   = true
}

variable "kamailio_dispatcher_targets" {
  description = "List of Asterisk endpoints inside the K8s cluster — kamailio dispatches calls to these"
  type        = list(object({
    host = string
    port = optional(number, 5060)
  }))
  default     = []
}
