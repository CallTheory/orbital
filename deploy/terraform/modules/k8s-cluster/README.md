# `k8s-cluster` module — provider-agnostic interface

Same shape as `edge-vm-pair`: an interface contract every per-provider
K8s cluster implementation honors. Concrete implementations live in
`terraform/<provider>/{self-hosted-k3s,managed-<x>}/cluster.tf`.

## Variables

| Variable               | Description                                                  |
|------------------------|--------------------------------------------------------------|
| `node_count`           | Worker node count                                            |
| `node_size`            | Per-provider worker size (e.g. `vc2-2c-4gb`)                 |
| `control_plane_size`   | K3s only — control-plane VM size                             |
| `control_plane_count`  | K3s — server replicas (1 dev, 3 HA)                          |
| `region`               | Cloud region                                                 |
| `kubernetes_version`   | K8s version                                                  |
| `ssh_public_key`       | K3s only — operator SSH key                                  |
| `ansible_repo_url`     | K3s only — repo for cloud-init bootstrap                     |
| `ansible_branch`       | K3s only — repo branch                                       |
| `k3s_token`            | K3s only — shared cluster token                              |

## Outputs

| Output                          | Description                                                |
|---------------------------------|------------------------------------------------------------|
| `kubeconfig`                    | Base64-encoded kubeconfig (sensitive)                      |
| `kubeconfig_path`               | Local path the kubeconfig was written to                   |
| `cluster_name`                  | Cluster identifier                                         |
| `cluster_endpoint`              | API server URL                                             |
| `asterisk_dispatcher_targets`   | In-cluster Asterisk endpoints kamailio dispatches to       |
| `ingress_lb_hostname`           | Ingress LoadBalancer DNS name (point DNS here)             |

## Implementations

| Provider             | Path                                          |
|----------------------|-----------------------------------------------|
| Vultr self-hosted K3s | `terraform/vultr/self-hosted-k3s/cluster.tf` |
| Vultr Kubernetes Engine | `terraform/vultr/managed-vke/cluster.tf`   |
| On-prem K3s          | `terraform/onprem/k3s/main.tf`                |
| AWS                  | (deferred)                                    |
| Azure                | (deferred)                                    |
