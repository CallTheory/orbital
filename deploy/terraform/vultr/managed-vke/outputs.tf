output "edge_ips" {
  description = "Public IPs of the edge VMs"
  value       = vultr_instance.edge[*].main_ip
}

output "edge_hostnames" {
  description = "Edge VM hostnames"
  value       = vultr_instance.edge[*].hostname
}

output "edge_vip" {
  description = "Reserved IP attached to edge-1 — point your SIP DNS at this"
  value       = vultr_reserved_ip.edge_vip.subnet
}

output "vke_cluster_id" {
  description = "VKE cluster ID"
  value       = vultr_kubernetes.cluster.id
}

output "vke_cluster_endpoint" {
  description = "VKE API endpoint"
  value       = vultr_kubernetes.cluster.endpoint
}

output "kubeconfig_path" {
  description = "Path to the kubeconfig OpenTofu wrote"
  value       = local_sensitive_file.kubeconfig.filename
}

output "kubeconfig_b64" {
  description = "Base64-encoded kubeconfig (sensitive)"
  value       = vultr_kubernetes.cluster.kube_config
  sensitive   = true
}

output "ssh_private_key_path" {
  description = "Path to the OpenTofu-generated SSH key"
  value       = local_sensitive_file.ssh_private_key.filename
}
