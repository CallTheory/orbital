# Local k3d cluster — Helm chart iteration

This directory spins up a 3-node K3s cluster inside Docker (via [k3d](https://k3d.io/)) so you can test the Helm chart's rendered manifests against a real Kubernetes API. Same topology as the on-prem K3s install (1 server + 2 agents, Traefik ingress, local-path storage), so chart bugs that only show up under multi-node scheduling get caught locally.

This is **not** the everyday Laravel dev path — that's still `./vendor/bin/sail up -d` from the repo root. Reach for k3d when:

- You're touching files under `deploy/helm/orbital/` and need to confirm a `helm install` works end-to-end.
- You need to validate provider-specific values overrides (`values-vultr-k3s.yaml`, `values-vultr-vke.yaml`, etc.) without spinning up real cloud infra.
- You're iterating on the K8s deployment story before pushing to a real cluster.

## Prerequisites

- [k3d](https://k3d.io/#installation) v5+
- [helm](https://helm.sh/docs/intro/install/) v3+
- `kubectl`
- Docker

## Bring it up

```bash
./k3d-up.sh
```

That:
1. Creates a k3d cluster named `orbital-dev` (1 server, 2 agents) with an embedded container registry on port 5001.
2. Creates the `orbital` namespace and a placeholder `orbital-orbital-app-secrets` secret with throwaway values (so chart `secretRef`s resolve).
3. Creates a placeholder `orbital-registry-creds` image-pull secret pointed at the local registry.
4. Runs `helm upgrade --install orbital ./deploy/helm/orbital -f values-onprem-k3s.yaml`.

Total wall time: 30–90 seconds depending on how much it has to pull.

## Reach the admin panel

The cluster's Traefik LoadBalancer maps to host ports 8080 (HTTP) and 8443 (HTTPS):

```bash
echo "127.0.0.1 orbital.localhost" | sudo tee -a /etc/hosts
open http://orbital.localhost:8080
```

Or skip ingress + DNS entirely with port-forward:

```bash
kubectl --namespace orbital port-forward svc/orbital-orbital-laravel 8000:80
open http://localhost:8000
```

## Tear it down

```bash
./k3d-down.sh
```

Removes the cluster + registry container + all PVCs. Nothing persists.

## Push a local image to the cluster's registry

The k3d cluster pulls images from `orbital-registry:5001` (resolvable inside the cluster). To test a local Laravel image build:

```bash
docker build -t orbital-registry:5001/orbital/laravel:dev -f docker/8.4/Dockerfile .
docker push orbital-registry:5001/orbital/laravel:dev
helm upgrade orbital ./deploy/helm/orbital \
    -n orbital \
    -f ./deploy/helm/orbital/values-onprem-k3s.yaml \
    --set global.image.registry=orbital-registry:5001 \
    --set global.image.tag=dev
```

The `k3d-up.sh` script already sets the registry/tag overrides on first install — `helm upgrade` later just rolls the new image.

## Common port collisions

If you forgot to `./vendor/bin/sail down` before running `k3d-up.sh`, the loadbalancer container will fail to bind 8080 / 8443. The script warns about this. Stop sail first:

```bash
./vendor/bin/sail down
./deploy/local/k3d-up.sh
```

If you want both running simultaneously, override the k3d host ports in `k3d-config.yaml` (e.g. `8081:80`, `8444:443`) — they don't conflict with sail's 80 / 443 once you do.

## Caveats

- **Single-node-style RWX**: K3s ships `local-path` (RWO-only). The chart's `asterisk-shared-config` PVC needs RWX. The `values-onprem-k3s.yaml` override sets `asterisk.replicas=1` to dodge this; multi-pod Asterisk on local-path will not schedule the second pod.
- **No real ingress TLS**: cert-manager isn't installed. The Ingress points at the cert-manager `letsencrypt-prod` ClusterIssuer that doesn't exist locally — Traefik serves over plain HTTP at port 8080 anyway. Production needs cert-manager + a real DNS name.
- **No real edge VMs**: the Kamailio + rtpengine edge lives outside the cluster (on real VMs). To test the SIP path locally, run the existing sail compose stack alongside k3d (different ports), or skip and test SIP on a real Vultr deployment.
