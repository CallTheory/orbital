# Outputs consumed by `scripts/install-vultr.sh` to chain into
# `helm install` once the cluster's up.

output "edge_ips" {
  description = "Public IPs of the edge VMs"
  value       = vultr_instance.edge[*].main_ip
}

output "edge_private_ips" {
  description = "VPC IPs of the edge VMs (used for DMQ peering)"
  value       = vultr_instance.edge[*].internal_ip
}

output "edge_hostnames" {
  description = "Edge VM hostnames"
  value       = vultr_instance.edge[*].hostname
}

output "edge_vip" {
  description = "Reserved IP attached to edge-1 — point your SIP DNS at this"
  value       = vultr_reserved_ip.edge_vip.subnet
}

output "k3s_server_ips" {
  description = "K3s server (control-plane) public IPs"
  value       = vultr_instance.k3s_server[*].main_ip
}

output "k3s_server_internal_ips" {
  description = "K3s server VPC IPs"
  value       = vultr_instance.k3s_server[*].internal_ip
}

output "k3s_agent_ips" {
  description = "K3s agent (worker) public IPs"
  value       = vultr_instance.k3s_agent[*].main_ip
}

output "k3s_api_endpoint" {
  description = "K3s API endpoint — kubeconfig server URL"
  value       = "https://${vultr_instance.k3s_server[0].main_ip}:6443"
}

output "k3s_token" {
  description = "Shared K3s cluster token (sensitive)"
  value       = random_password.k3s_token.result
  sensitive   = true
}

output "ssh_private_key_path" {
  description = "Path to the OpenTofu-generated SSH key — used by Ansible / scripts"
  value       = local_sensitive_file.ssh_private_key.filename
}

output "vpc_id" {
  description = "Vultr VPC ID"
  value       = vultr_vpc.main.id
}
