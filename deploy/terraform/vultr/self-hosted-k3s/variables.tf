# Variables — fill these in via `terraform.tfvars` (which is .gitignored)
# or `-var-file` / `-var` flags.

variable "vultr_api_key" {
  description = "Vultr API key (https://my.vultr.com/settings/#settingsapi)"
  type        = string
  sensitive   = true
}

variable "region" {
  description = "Vultr region code — `vultr regions list` for the full list"
  type        = string
  default     = "ewr"
}

variable "ssh_public_key" {
  description = "Operator SSH public key — provisioned to the orbital admin user"
  type        = string
}

variable "admin_authorized_keys" {
  description = "Additional SSH keys for the orbital admin user"
  type        = list(string)
  default     = []
}

# --- Edge VM sizing ---
variable "edge_instance_size" {
  description = "Vultr plan for edge VMs (kamailio + rtpengine). Defaults to 2 vCPU / 4 GB."
  type        = string
  default     = "vc2-2c-4gb"
}

variable "edge_instance_count" {
  description = "Number of edge VMs (Phase 1: 2 = active/active)"
  type        = number
  default     = 2
}

# --- K3s cluster sizing ---
variable "k3s_server_size" {
  description = "Vultr plan for K3s server (control plane). 4 vCPU / 8 GB recommended."
  type        = string
  default     = "vc2-4c-8gb"
}

variable "k3s_server_count" {
  description = "Number of K3s server (control-plane) nodes. 1 for small / dev, 3 for HA."
  type        = number
  default     = 1
}

variable "k3s_agent_size" {
  description = "Vultr plan for K3s agent (worker) nodes. Sized for the app stack."
  type        = string
  default     = "vc2-4c-8gb"
}

variable "k3s_agent_count" {
  description = "Number of K3s agent (worker) nodes"
  type        = number
  default     = 2
}

# --- DNS + TLS ---
variable "tls_email" {
  description = "Email for ACME (Let's Encrypt) registration"
  type        = string
}

variable "tls_domain" {
  description = "SIP edge domain (e.g. sip.orbital.example.com) — cert SAN will include this"
  type        = string
}

# --- Cluster identity ---
variable "cluster_name" {
  description = "Cluster identifier — used as Vultr resource label prefix"
  type        = string
  default     = "orbital"
}

# --- Repo bootstrap ---
variable "ansible_repo_url" {
  description = "Git URL the cloud-init stub `ansible-pull`s from"
  type        = string
}

variable "ansible_branch" {
  description = "Git branch"
  type        = string
  default     = "main"
}

variable "tls_webhook_url" {
  description = "Laravel TLS-renewal webhook URL — for cert reload fan-out"
  type        = string
}

variable "tls_webhook_token" {
  description = "Webhook shared secret"
  type        = string
  sensitive   = true
}
