# `helm/orbital/` — the Orbital Helm chart

Provider-agnostic K8s deployment of the Orbital application stack. Drives Laravel admin + Horizon + Reverb, Asterisk, LiveKit + LiveKit-SIP, the agent-worker — everything that lives "inside the cluster." The kamailio + rtpengine edge runs on bare VMs (provisioned separately by the matching `terraform/<provider>/` entrypoint), not in this chart.

## What ships in the chart

| Component        | Kind          | Replicas (default) |
|------------------|---------------|--------------------|
| Laravel          | Deployment    | 2                  |
| Horizon          | Deployment    | 1                  |
| Reverb           | Deployment    | 1                  |
| Asterisk         | StatefulSet   | 2                  |
| LiveKit          | Deployment    | 1                  |
| LiveKit-SIP      | Deployment    | 1                  |
| agent-worker     | Deployment    | 2                  |
| Postgres         | StatefulSet   | 1 (in-cluster)     |
| Valkey           | StatefulSet   | 1 (in-cluster)     |
| SeaweedFS        | StatefulSet   | 1 (in-cluster)     |
| Ingress          | Ingress       | 1                  |

Default install lands all 26 resources. Flipping `<tier>.external.enabled=true` (Postgres / Valkey / Object storage) drops the in-cluster StatefulSet + Service + ConfigMap and the Filament admin's FailoverCentral page hides those tier rows.

## Per-provider values matrix

| Knob                  | onprem-k3s          | vultr-k3s              | vultr-vke              |
|-----------------------|---------------------|------------------------|------------------------|
| `global.storageClass` | `local-path`        | `vultr-block-storage`  | `vultr-block-storage`  |
| `ingress.className`   | `traefik`           | `traefik`              | `nginx`                |
| `asterisk.replicas`   | 1                   | 1                      | 1                      |
| LB type               | klipper-lb          | Vultr LB               | Vultr LB (managed)     |

Apply with `-f values-<provider>-<flavor>.yaml`. Mix overrides freely: a customer running the chart on EKS could write `values-aws-eks.yaml` and pass that.

## Image registry contract

Orbital images live in **Patrick's private Harbor** at `cr.calltheory.com` (URL set via `global.image.registry`). Customers receive **per-customer Harbor robot accounts** with pull-only access. To install:

```bash
# 1. Operator hands customer their robot username + token
kubectl create secret docker-registry orbital-registry-creds \
    --namespace orbital \
    --docker-server=cr.calltheory.com \
    --docker-username='robot$<customer>-pull' \
    --docker-password='<token>'

# 2. Helm install pulls images from Harbor with that secret
helm install orbital ./deploy/helm/orbital \
    -n orbital \
    -f values-vultr-k3s.yaml
```

Revoking access = deleting the robot account in Harbor; existing pods stay up until they roll.

Third-party images (LiveKit, Postgres, Valkey, SeaweedFS) pull from `docker.io` directly. Each component's `image.registry` in values is overridable per install for air-gapped or mirror setups.

## Required app secrets

Create one secret named `<release>-orbital-app-secrets` in the namespace before `helm install`:

```bash
kubectl create secret generic orbital-orbital-app-secrets \
    --namespace orbital \
    --from-literal=APP_KEY="base64:$(openssl rand -base64 32)" \
    --from-literal=DB_PASSWORD="..." \
    --from-literal=REDIS_PASSWORD="..." \
    --from-literal=AWS_ACCESS_KEY_ID="..." \
    --from-literal=AWS_SECRET_ACCESS_KEY="..." \
    --from-literal=LIVEKIT_API_KEY="..." \
    --from-literal=LIVEKIT_API_SECRET="..." \
    --from-literal=ASTERISK_AMI_SECRET="..." \
    --from-literal=ANTHROPIC_API_KEY="..." \
    --from-literal=OPENAI_API_KEY="..." \
    --from-literal=ELEVENLABS_API_KEY="..."
```

`templates/secrets.example.yaml` has the full recipe. The chart's `install-{vultr,onprem}.sh` scripts auto-generate this with sensible random values when env vars aren't provided.

## Linting + smoke testing

```bash
# Static lint
helm lint deploy/helm/orbital

# Render to stdout (no install) — sanity-check templates
helm template orbital deploy/helm/orbital -n orbital | less

# Live install on a local k3d cluster
deploy/local/k3d-up.sh
```

## CI chart releases

Out of scope for v1. Manual `helm install ./deploy/helm/orbital` is the supported path. Once the Forgejo Actions runner is online, a `publish-helm.yml` workflow on tag push will `helm package` + `helm push oci://cr.calltheory.com/orbital/charts/orbital`.

## Air-gapped customers

Standard `skopeo copy` pattern — mirror Patrick's Harbor images into the customer's internal Harbor, then install with `--set global.image.registry=harbor.<customer>.local`. No chart-side support needed.
