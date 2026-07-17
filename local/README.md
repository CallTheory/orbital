# Local k3d cluster — the staging environment

This directory spins up a 3-node K3s cluster inside Docker (via [k3d](https://k3d.io/)) so you can test the Helm chart's rendered manifests against a real Kubernetes API. Same topology as the on-prem K3s install (1 server + 2 agents, Traefik ingress, local-path storage), so chart bugs that only show up under multi-node scheduling get caught locally.

This is **Orbital's staging environment** — the production-shaped deployment you validate changes against before pushing to Vultr. See the root [`README.md`](../README.md#environments) for how the three environments (dev / staging / production) relate. The two commands you'll use most:

- **First bring-up:** `./k3d-up.sh`
- **Redeploy your code changes:** `./k3d-build.sh` (or `EDGE=1 ./k3d-build.sh` when the SIP edge is running)

This is **not** the everyday Laravel dev path — that's still `./vendor/bin/sail up -d` from the repo root. Reach for k3d when:

- You're touching files under `helm/orbital/` and need to confirm a `helm install` works end-to-end.
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
4. Runs `helm upgrade --install orbital ./helm/orbital -f values-onprem-k3s.yaml`.

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

## Redeploy your code changes — `k3d-build.sh`

After the cluster is up, this is the single command that pulls your working tree into staging. It builds the four first-party images (laravel, agent-worker, asterisk, asterisk-config-sync) under a **unique tag**, pushes them to the cluster registry, and rolls the release onto that tag:

```bash
./k3d-build.sh                 # rebuild + redeploy everything
./k3d-build.sh laravel         # only rebuild laravel (others keep their current tag)
./k3d-build.sh laravel asterisk
```

Why a unique tag per build instead of `:dev`: reusing a fixed tag makes `helm upgrade` see no spec change, so it keeps the **old** pods running — you get "green but running old code," and even a `rollout restart` won't re-pull under `imagePullPolicy: IfNotPresent`. A fresh `dev-<timestamp>` tag every build forces a real rollout with zero of that ambiguity.

Watch it roll:

```bash
kubectl -n orbital get pods -w
```

### With the SIP edge running

If you've brought up the local edge (below), redeploy with `EDGE=1` so the Kamailio/rtpengine wiring survives the upgrade:

```bash
EDGE=1 ./k3d-build.sh
```

`EDGE=1` layers `values-local-edge.yaml` on top and re-pins the k3d node IP that Asterisk advertises in SDP. A plain `./k3d-build.sh` would reset Asterisk to the non-edge defaults and drop the NodePort exposure mid-session.

## Common port collisions

If you forgot to `./vendor/bin/sail down` before running `k3d-up.sh`, the loadbalancer container will fail to bind 8080 / 8443. The script warns about this. Stop sail first:

```bash
./vendor/bin/sail down
./local/k3d-up.sh
```

If you want both running simultaneously, override the k3d host ports in `k3d-config.yaml` (e.g. `8081:80`, `8444:443`) — they don't conflict with sail's 80 / 443 once you do.

## Caveats

- **Storage is all RWO now**: the Asterisk dialplan/prompt handoff goes through object storage (in-cluster SeaweedFS), not a shared ReadWriteMany PVC — so `local-path` (RWO-only) is sufficient and Asterisk scales to multiple pods on it. Each Asterisk pod pulls its own config copy via a `config-sync` sidecar. If the `config-sync` container is stuck, check `kubectl -n orbital logs <asterisk-pod> -c config-sync` (and `-c config-sync-init` for boot-time sync).
- **No real ingress TLS**: cert-manager isn't installed. The Ingress points at the cert-manager `letsencrypt-prod` ClusterIssuer that doesn't exist locally — Traefik serves over plain HTTP at port 8080 anyway. Production needs cert-manager + a real DNS name.
- **The SIP edge is off-cluster**: in production the Kamailio + rtpengine edge runs on real VMs (provisioned by orbital-setup). Locally you can stand up an equivalent edge on the k3d docker network — see the next section.

## Optional: local SIP edge (real call path)

To place actual calls against the cluster, bring up an off-cluster Kamailio + rtpengine edge that joins the k3d docker network and dispatches to Asterisk's NodePort. This mirrors the prod shape (edge outside, app tier inside) without host networking.

```bash
./edge-up.sh          # Kamailio + rtpengine on the k3d docker network
./edge-register.sh    # seed the rtpengine + Asterisk-backend rows so the app's
                      #   health cards + dispatcher reload work
EDGE=1 ./k3d-build.sh # (re)deploy the cluster side with the edge overlay
```

`edge-up.sh` discovers a k3d node IP, points Kamailio's dispatcher at the cluster's `asterisk-edge` NodePort, and self-signs a cert for the TLS/WSS listeners. If your softphone runs on Windows or another host (not inside WSL / the docker network), set `EDGE_RTP_ADVERTISE_IP` to a host-reachable address so rtpengine advertises the right media IP.

Tear the edge down with `./edge-down.sh`. It's independent of the cluster — you can cycle the edge without touching the k3d deployment.
