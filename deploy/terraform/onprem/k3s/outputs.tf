output "edge_ips" {
  description = "Edge VM IPs (echoed back from input for symmetry with cloud entrypoints)"
  value       = var.edge_vm_ips
}

output "k3s_server_ips" {
  description = "K3s server IPs"
  value       = var.k3s_server_ips
}

output "k3s_agent_ips" {
  description = "K3s agent IPs"
  value       = var.k3s_agent_ips
}

output "kubeconfig_path" {
  description = "Path on this controller where the K3s playbook saved the kubeconfig (after first run)"
  value       = "${var.playbook_dir}/k3s-server-1.kubeconfig"
}

output "ansible_inventory_path" {
  description = "Path to the generated Ansible inventory"
  value       = local_file.inventory.filename
}
