# edge-vm-pair — output contract.
#
# Every per-provider implementation populates these outputs from
# its native resources. The top-level entrypoint reads them to
# (a) produce the OpenTofu kubeconfig + edge IP outputs, and
# (b) hand `kamailio_dispatcher_targets` to the K8s cluster
# module so the dispatcher list points at the right Service IPs.

output "edge_ips" {
  description = "Public IPs of every edge VM"
  type        = list(string)
  value       = []  # overridden by per-provider implementation
}

output "edge_private_ips" {
  description = "Private / VPC IPs of every edge VM (used for DMQ peering)"
  type        = list(string)
  value       = []
}

output "edge_hostnames" {
  description = "Hostnames of every edge VM (for ssh / ansible inventory)"
  type        = list(string)
  value       = []
}

output "kamailio_floating_ip" {
  description = "Failover-floating IP for the SIP edge (VRRP / anycast / DNS RR — provider-specific)"
  type        = string
  value       = ""
}

output "ssh_private_key_path" {
  description = "Path to the SSH key OpenTofu generated, used by Ansible for inventory access"
  type        = string
  value       = ""
}
