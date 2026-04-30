# k8s-cluster — variable contract.
#
# Per-provider implementations pick from:
#   - "self-hosted K3s on cloud VMs" (vultr_instance + Ansible install)
#   - "managed K8s service" (vultr_kubernetes / aws_eks_cluster / etc.)
#
# The contract is the same: the customer asks for N nodes of size X
# in region R; the module returns a kubeconfig plus a load-balancer
# hostname they point DNS at.

variable "node_count" {
  description = "Number of K8s worker nodes (3 = sensible default for HA)"
  type        = number
  default     = 3

  validation {
    condition     = var.node_count >= 1
    error_message = "node_count must be at least 1."
  }
}

variable "node_size" {
  description = "Per-provider worker-node size identifier"
  type        = string
}

variable "control_plane_size" {
  description = "Self-hosted K3s only — control-plane VM size (managed K8s ignores)"
  type        = string
  default     = ""
}

variable "control_plane_count" {
  description = "Self-hosted K3s — number of control-plane (server) nodes; 1 for dev, 3 for HA"
  type        = number
  default     = 1
}

variable "region" {
  description = "Cloud region"
  type        = string
}

variable "kubernetes_version" {
  description = "K8s version (e.g. v1.31.2 for K3s, 1.31 for managed offerings)"
  type        = string
  default     = "v1.31.2"
}

variable "ssh_public_key" {
  description = "Operator SSH key (self-hosted K3s only — managed K8s nodes are owned by the cloud)"
  type        = string
  default     = ""
}

variable "ansible_repo_url" {
  description = "Repo URL for self-hosted K3s cloud-init bootstrap"
  type        = string
  default     = ""
}

variable "ansible_branch" {
  description = "Repo branch for self-hosted K3s cloud-init"
  type        = string
  default     = "main"
}

variable "k3s_token" {
  description = "Self-hosted K3s shared cluster token; ignored by managed offerings"
  type        = string
  default     = ""
  sensitive   = true
}
