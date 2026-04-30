# Vultr Kubernetes Engine (managed) — `tofu apply` walkthrough

This entrypoint provisions Orbital with **VKE** as the K8s control
plane (managed by Vultr) instead of self-hosted K3s. Trade-off:

- **Pro**: Vultr handles K8s upgrades, etcd, certificate rotation
- **Pro**: native Vultr LB / Block Storage CSI without operator setup
- **Con**: VKE control-plane fee (~$10/mo per cluster); slightly less control
- **Con**: VKE versions trail upstream K8s by 1–2 minor releases

If you don't have a strong reason to want managed control plane,
the self-hosted-K3s entrypoint at `../self-hosted-k3s/` is simpler
and free.

## Prerequisites

- [OpenTofu](https://opentofu.org/docs/intro/install/) ≥ 1.7
- Vultr API key
- A DNS zone you control

## State backend

Same recommendation as `../self-hosted-k3s/README.md` — Vultr Object
Storage S3 backend, manually-created bucket.

## Workflow

```bash
cp terraform.tfvars.example terraform.tfvars
${EDITOR:-vim} terraform.tfvars

tofu init
tofu plan -var-file=terraform.tfvars
tofu apply -var-file=terraform.tfvars

# kubeconfig is written to .kube/kubeconfig-<cluster-name>
export KUBECONFIG=$(tofu output -raw kubeconfig_path)
kubectl get nodes

# helm install:
cd ../../../..
./deploy/scripts/install-vultr.sh --variant=managed-vke
```

## What's different from self-hosted-K3s

| Knob                  | self-hosted-K3s              | managed-VKE                  |
|-----------------------|------------------------------|------------------------------|
| Control plane         | K3s servers (you operate)    | Vultr-managed                |
| Helm values file      | `values-vultr-k3s.yaml`      | `values-vultr-vke.yaml`      |
| Ingress class         | `traefik`                    | `nginx` (install separately) |
| Storage class         | `vultr-block-storage`        | `vultr-block-storage`        |
| K8s upgrades          | re-run `tofu apply` w/ new version | Vultr console one-click |
| Cluster cost          | $$$ per node only            | $$$ per node + $10/mo CP fee |

## VKE caveat: ingress controller install

VKE does NOT ship an ingress controller out of the box. After
`tofu apply` finishes but before `helm install` lands the chart,
install ingress-nginx + cert-manager:

```bash
helm repo add ingress-nginx https://kubernetes.github.io/ingress-nginx
helm repo add jetstack https://charts.jetstack.io
helm repo update

helm install ingress-nginx ingress-nginx/ingress-nginx \
    --namespace ingress-nginx --create-namespace \
    --set controller.service.type=LoadBalancer

helm install cert-manager jetstack/cert-manager \
    --namespace cert-manager --create-namespace \
    --set crds.enabled=true
```

The `install-vultr.sh --variant=managed-vke` script handles this
automatically — see `deploy/scripts/install-vultr.sh`.
