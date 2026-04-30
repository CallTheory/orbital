# k8s-cluster — output contract.
#
# Per-provider implementations populate these from their native
# resources (vultr_kubernetes, aws_eks_cluster, etc.) or from
# their self-hosted K3s install Ansible playbook outputs.

output "kubeconfig" {
  description = "Base64-encoded kubeconfig — operator decodes + writes to ~/.kube/config"
  type        = string
  value       = ""
  sensitive   = true
}

output "kubeconfig_path" {
  description = "Path on the controller where the kubeconfig was written (after `local-exec`)"
  type        = string
  value       = ""
}

output "cluster_name" {
  description = "Cluster identifier (used in the OpenTofu Helm provider)"
  type        = string
  value       = ""
}

output "cluster_endpoint" {
  description = "API server URL"
  type        = string
  value       = ""
}

output "asterisk_dispatcher_targets" {
  description = "Stable in-cluster endpoints kamailio dispatches calls to"
  type        = list(object({
    host = string
    port = number
  }))
  value       = []
}

output "ingress_lb_hostname" {
  description = "Hostname of the cluster's ingress LoadBalancer — point DNS at this"
  type        = string
  value       = ""
}
