# Variables — Vultr Kubernetes Engine (managed control plane).

variable "vultr_api_key" {
  description = "Vultr API key"
  type        = string
  sensitive   = true
}

variable "region" {
  description = "Vultr region — must match a region VKE supports"
  type        = string
  default     = "ewr"
}

variable "ssh_public_key" {
  description = "Operator SSH public key for edge VMs (VKE worker nodes are managed)"
  type        = string
}

variable "admin_authorized_keys" {
  description = "Additional SSH keys for the orbital admin user on edge VMs"
  type        = list(string)
  default     = []
}

# --- VKE node pool ---
variable "vke_version" {
  description = "VKE Kubernetes version — `vultr-cli kubernetes versions` for the menu"
  type        = string
  default     = "v1.31.0+1"
}

variable "vke_node_pool_size" {
  description = "Vultr plan for VKE worker nodes"
  type        = string
  default     = "vc2-4c-8gb"
}

variable "vke_node_pool_count" {
  description = "Number of VKE worker nodes"
  type        = number
  default     = 3
}

variable "vke_node_pool_label" {
  description = "Label for the worker node pool"
  type        = string
  default     = "orbital-workers"
}

# --- Edge VMs (same as self-hosted-k3s) ---
variable "edge_instance_size" {
  description = "Vultr plan for edge VMs"
  type        = string
  default     = "vc2-2c-4gb"
}

variable "edge_instance_count" {
  description = "Number of edge VMs"
  type        = number
  default     = 2
}

# --- DNS + TLS ---
variable "tls_email" {
  description = "ACME registration email"
  type        = string
}

variable "tls_domain" {
  description = "SIP edge domain"
  type        = string
}

variable "tls_webhook_url" {
  description = "Laravel TLS webhook URL"
  type        = string
}

variable "tls_webhook_token" {
  description = "Webhook shared secret"
  type        = string
  sensitive   = true
}

# --- Cluster identity ---
variable "cluster_name" {
  description = "Cluster identifier"
  type        = string
  default     = "orbital"
}

# --- Repo source for cloud-init's ansible-pull on edge VMs ---
variable "ansible_repo_url" {
  description = "Git URL"
  type        = string
}

variable "ansible_branch" {
  description = "Git branch"
  type        = string
  default     = "main"
}
