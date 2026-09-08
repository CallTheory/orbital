# `helm/orbital/` — the Orbital Helm chart

Provider-agnostic K8s deployment of the Orbital application stack. Drives Laravel admin + Horizon + Reverb, Asterisk, LiveKit + LiveKit-SIP, the agent-worker — everything that lives "inside the cluster." The kamailio + rtpengine edge runs on bare VMs (provisioned separately by the matching `terraform/<provider>/` entrypoint in the **orbital-setup** repo), not in this chart.

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
| Migrations       | Job (hook)    | 1 per release      |
| Disruption budgets | PDB         | one per multi-replica tier |

Default install lands all 26 resources.

### Redundancy: what "2 replicas" actually buys

Two knobs, both on by default, because replica count alone does not
survive anything:

- **`spreadPods`** adds a `topologySpreadConstraints` block to every
  multi-replica tier. Kubernetes schedules for fit, not for surviving a
  node loss, so without this both Laravel replicas can land on the same
  node and one node failure takes the tier down. `required: false` by
  default so single-node dev and k3s clusters still schedule; production
  overlays set `required: true` to make co-location a scheduling error
  rather than a silent risk.
- **`podDisruptionBudgets`** protects against *planned* work — a
  `kubectl drain`, a VKE node upgrade, an autoscaler scale-down. Without
  a budget those evict with no regard for how many of a tier survive.
  Rendered only for tiers with replicas > 1; a PDB over a single-replica
  Deployment permits zero evictions and blocks drains forever.

**The data tier is a different story.** Postgres, Valkey, and SeaweedFS
ship as single-replica StatefulSets — enough for a first install, not
for production. This chart does not attempt to be a database operator:
for real deployments set `<tier>.external.enabled=true` and point at a
managed service (Vultr Managed PostgreSQL, Vultr Object Storage) or run
a dedicated operator alongside. The compose stack's Patroni/etcd/
SeaweedFS-cluster topology has not been ported here, and installing an
untested hand-rolled Patroni is worse than using a managed database.

`helm install` prints a warning for every tier still running
single-replica in-cluster, and another if backups are off.

### Schema migrations

`templates/migrate-job.yaml` runs `php artisan migrate --force` as a Helm
hook. Nothing else in the chart applies migrations, so with
`migrations.enabled=false` you own that step yourself.

The hook timing is deliberate and the two halves differ:

- **`post-install`** — on a fresh release the in-cluster Postgres
  StatefulSet does not exist during `pre-install`, so there would be
  nothing to connect to. An init container then waits (up to
  `migrations.waitForDbSeconds`) for the database to accept connections.
- **`pre-upgrade`** — the database already exists, so this runs *before*
  the new pods roll. That ordering is the point: no pod ever serves new
  code against an old schema.

On a **first install only**, `migrations.seedOnInstall` also runs
`db:seed --force`. A migrated-but-unseeded database has no permission
catalogue, no roles, and no first super admin — the panel loads into a
state nobody can log in to and administer. It is never re-run on
upgrade.

A failed migration fails the Helm release, so a broken schema change
stops the rollout instead of half-applying it. The Job is kept on
failure (`hook-delete-policy: hook-succeeded`) so `kubectl logs` still
has the error. Flipping `<tier>.external.enabled=true` (Postgres / Valkey / Object storage) drops the in-cluster StatefulSet + Service + ConfigMap and the Filament admin's FailoverCentral page hides those tier rows.

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

# 2. Helm install pulls images from Harbor with that secret.
#    Customers without source access install from Harbor's OCI registry:
helm install orbital oci://cr.calltheory.com/orbital/charts/orbital \
    --version 0.1.1 \
    -n orbital --create-namespace \
    -f values-vultr-k3s.yaml

# Or, with the source repo cloned:
helm install orbital ./helm/orbital \
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
helm lint helm/orbital

# Render to stdout (no install) — sanity-check templates
helm template orbital helm/orbital -n orbital | less

# Live install on a local k3d cluster
local/k3d-up.sh
```

## CI chart releases

`.forgejo/workflows/publish-helm.yml` packages and pushes the chart to Harbor on every `v*` tag, alongside the application images from `publish-images.yml`. Chart and image versions ship from the same git tag, so they never drift. Customers (and the orbital-setup install scripts) `helm pull oci://cr.calltheory.com/orbital/charts/orbital --version <X>`.

## Air-gapped customers

Standard `skopeo copy` pattern — mirror Patrick's Harbor images into the customer's internal Harbor, then install with `--set global.image.registry=harbor.<customer>.local`. No chart-side support needed.
