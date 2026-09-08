# Deploying to Vultr Kubernetes (VKE)

A step-by-step first deployment, written for someone who has not used
VKE before. Follow it in order; each part says what you should see
before moving on.

> **Automation exists for parts 1–6.** The `orbital-setup` repo has
> OpenTofu under `terraform/vultr/managed-vke/` that provisions the
> cluster, the SIP edge VMs, ingress-nginx and cert-manager in one go.
> This page does it by hand instead, so you can see what the automation
> is doing and debug it when it misbehaves. If you'd rather run the
> tofu, do that and skip to **Part 7**.

> **Calls will not work at the end of this page.** The admin panel, the
> portal, email and messaging will. SIP and RTP run on edge VMs outside
> the cluster (Kamailio + rtpengine) — deliberately, because media on
> Kubernetes is painful. Part 12 covers that.

---

## What you need before starting

| Thing | Notes |
|---|---|
| A Vultr account with billing | VKE control plane is free; you pay for worker nodes |
| A domain you control | You'll point a record at a load balancer |
| Harbor pull credentials | Your `robot$…-pull` username + token for `cr.calltheory.com` |
| A terminal | `kubectl` and `helm`, installed below |

Budget roughly 45–90 minutes for a first run, most of it waiting for
Vultr to provision things.

---

## Part 0 — Install the tools

**kubectl** — talks to Kubernetes.

```bash
curl -LO "https://dl.k8s.io/release/$(curl -Ls https://dl.k8s.io/release/stable.txt)/bin/linux/amd64/kubectl"
sudo install -o root -g root -m 0755 kubectl /usr/local/bin/kubectl
kubectl version --client
```

**helm** — installs the Orbital chart.

```bash
curl -fsSL https://raw.githubusercontent.com/helm/helm/main/scripts/get-helm-3 | bash
helm version
```

---

## Part 1 — Create the cluster

In the Vultr console, go to **Kubernetes** and add a cluster.

- **Region** — pick the one nearest your callers. Put *everything* in
  this same region: the database, the object storage, and the edge VMs.
  Cross-region hops add latency to every call.
- **Version** — the current stable Kubernetes offered.
- **Node pool** — this is the part worth getting right:

> **Three nodes, smallest sensible size.** Node *count* is what buys
> redundancy; node *size* only buys headroom. The chart is configured to
> refuse to put two replicas of a tier on the same node, so with fewer
> than three you lose that protection. Start with **3 × (2 vCPU / 4 GB)**
> and scale the plan up later if pods are getting evicted for memory —
> that is a one-click change, whereas going from one node to three
> changes what survives a failure.

Give the pool a label like `orbital`, create, and wait until all three
nodes show **Running**. This takes a few minutes.

---

## Part 2 — Point kubectl at the cluster

On the cluster's page, download the **kubeconfig** file.

```bash
mkdir -p ~/.kube
mv ~/Downloads/vke-*.yaml ~/.kube/orbital-vke.yaml
chmod 600 ~/.kube/orbital-vke.yaml
export KUBECONFIG=~/.kube/orbital-vke.yaml

kubectl get nodes
```

**You should see three nodes, all `Ready`.** If this hangs or says
"connection refused", the cluster is still provisioning — wait and retry.

> Keep that `export KUBECONFIG=...` in every terminal you use for the
> rest of this page, or put it in your shell profile. Almost every
> confusing failure below is actually "kubectl is pointed somewhere
> else."

Create the namespace everything will live in:

```bash
kubectl create namespace orbital
```

---

## Part 3 — Managed PostgreSQL

Vultr console → **Databases** → add a **PostgreSQL** database, same
region as the cluster.

Once it is running, from its overview page note the **host**, **port**
(it is *not* 5432 — Vultr uses a high port like `16751`), **username**,
and **password**.

Then create a database and user for Orbital rather than using the
default admin account. The console's "Users" and "Databases" tabs will
do this, or connect with `psql` and run the equivalent.

### Check pgvector before you go any further

**This is the one prerequisite that will fail your install if it's
missing.** Orbital's knowledge-store migration runs
`CREATE EXTENSION IF NOT EXISTS vector`, so a PostgreSQL without
pgvector available fails the migration job and Helm rolls the release
back.

```bash
psql "postgresql://<user>:<pass>@<host>:<port>/orbital?sslmode=require" \
  -c "CREATE EXTENSION IF NOT EXISTS vector;" \
  -c "SELECT extversion FROM pg_extension WHERE extname='vector';"
```

- **Prints a version** — you're good, continue.
- **Errors with "extension \"vector\" is not available"** — this managed
  database can't run Orbital. Either enable the extension in the Vultr
  console if it's offered, or skip Part 3 entirely and let the chart run
  its own in-cluster PostgreSQL, which ships with pgvector built in.
  That trades managed backups for a single-replica database — if you go
  that way, Part 5's backups become mandatory rather than merely
  strongly advised.

Also add your own IP (and later the cluster's) to the database's
**trusted sources / firewall** list, or connections are refused.

---

## Part 4 — Object storage

Vultr console → **Object Storage** → add an instance in the same region.
Note the **hostname** (e.g. `ewr1.vultrobjects.com`), **access key**, and
**secret key**.

Create **two buckets**:

| Bucket | Holds |
|---|---|
| `orbital-media` | Call recordings, MMS media, mail attachments |
| `orbital-backups` | Encrypted database backups |

> **Two buckets, and ideally two keys.** Backups kept in the same bucket
> under the same credential the app writes recordings with are destroyed
> by the same mistake or compromise that destroys the recordings — which
> is the one failure a backup exists to survive. If Vultr lets you issue
> a second key scoped to the backups bucket, do that.

Turn on **versioning** on `orbital-media` if the provider offers it.
Orbital's backups cover the database, not bucket contents, so
versioning is what protects recordings from an accidental delete.

---

## Part 5 — Ingress and TLS

VKE ships no ingress controller. Install one, plus cert-manager for
Let's Encrypt certificates.

```bash
helm repo add ingress-nginx https://kubernetes.github.io/ingress-nginx
helm repo add jetstack https://charts.jetstack.io
helm repo update

helm install ingress-nginx ingress-nginx/ingress-nginx \
  --namespace ingress-nginx --create-namespace

helm install cert-manager jetstack/cert-manager \
  --namespace cert-manager --create-namespace \
  --set crds.enabled=true
```

Wait for Vultr to allocate a load balancer — this takes a minute or two:

```bash
kubectl get svc -n ingress-nginx -w
```

**You want an `EXTERNAL-IP` that is a real address, not `<pending>`.**
Write it down. Press Ctrl-C when it appears.

Now create the certificate issuer:

```bash
kubectl apply -f - <<'EOF'
apiVersion: cert-manager.io/v1
kind: ClusterIssuer
metadata:
  name: letsencrypt-prod
spec:
  acme:
    server: https://acme-v02.api.letsencrypt.org/directory
    email: you@example.com          # <-- change this
    privateKeySecretRef:
      name: letsencrypt-prod
    solvers:
      - http01:
          ingress:
            class: nginx
EOF
```

---

## Part 6 — DNS

Point an `A` record for your chosen hostname (say
`orbital.yourcompany.com`) at the load balancer IP from Part 5.

**Do this before installing Orbital.** cert-manager proves you own the
domain by answering an HTTP challenge at that address; if DNS isn't
resolving yet the certificate silently fails to issue and you get a
browser warning instead of a working site.

Confirm it has propagated:

```bash
dig +short orbital.yourcompany.com
```

---

## Part 7 — Secrets

Three secrets. Nothing here goes in a values file or in git.

**7a. Harbor pull credentials** — without this every pod sits in
`ImagePullBackOff`.

```bash
kubectl create secret docker-registry orbital-registry-creds \
  --namespace orbital \
  --docker-server=cr.calltheory.com \
  --docker-username='robot$yourcustomer-pull' \
  --docker-password='<token>'
```

> Note the single quotes around the username. Harbor robot names contain
> a `$`, and without quoting your shell eats it.

**7b. Generate a backup encryption key.**

```bash
docker run --rm cr.calltheory.com/orbital/laravel:0.1.0 \
  php artisan orbital:backup --generate-key
```

**Put that key in your password manager now.** Losing it means losing
every backup taken with it — there is no recovery path, deliberately.
Storing it only inside the cluster it protects defeats the purpose.

**7c. The application secrets.**

```bash
kubectl create secret generic orbital-orbital-app-secrets \
  --namespace orbital \
  --from-literal=APP_KEY="base64:$(openssl rand -base64 32)" \
  --from-literal=DB_PASSWORD='<managed-postgres-password>' \
  --from-literal=REDIS_PASSWORD="$(openssl rand -hex 24)" \
  --from-literal=REVERB_APP_SECRET="$(openssl rand -hex 24)" \
  --from-literal=AWS_ACCESS_KEY_ID='<object-storage-key>' \
  --from-literal=AWS_SECRET_ACCESS_KEY='<object-storage-secret>' \
  --from-literal=LIVEKIT_API_KEY="$(openssl rand -hex 16)" \
  --from-literal=LIVEKIT_API_SECRET="$(openssl rand -hex 32)" \
  --from-literal=ASTERISK_AMI_SECRET="$(openssl rand -hex 24)" \
  --from-literal=BACKUP_ENCRYPTION_KEY='<key from 7b>' \
  --from-literal=BACKUP_AWS_ACCESS_KEY_ID='<backup-bucket-key>' \
  --from-literal=BACKUP_AWS_SECRET_ACCESS_KEY='<backup-bucket-secret>' \
  --from-literal=ANTHROPIC_API_KEY='' \
  --from-literal=OPENAI_API_KEY='' \
  --from-literal=ELEVENLABS_API_KEY=''
```

> The secret name is `<release>-orbital-app-secrets`. Installing the
> release as `orbital` makes that `orbital-orbital-app-secrets` — the
> doubled word is correct, not a typo.

> The AI keys are left blank on purpose. They're easier to set later in
> **Settings → Platform → AI Providers**, where they're stored encrypted
> in the database. The blank entries just keep the pods from complaining
> about missing variables.

**7d. Managed database credentials** (skip if you're using in-cluster
Postgres):

```bash
kubectl create secret generic postgres-creds \
  --namespace orbital \
  --from-literal=password='<managed-postgres-password>'
```

---

## Part 8 — Your values file

Create `orbital-values.yaml` locally. This is the file you keep and
re-use for every upgrade.

```yaml
global:
  domain: orbital.yourcompany.com
  image:
    tag: "0.1.0"                    # pin a real release, never :dev

postgres:
  external:
    enabled: true
    host: "<managed-pg-host>"
    port: 16751                     # Vultr's port, NOT 5432
    database: orbital
    username: orbital
    sslMode: require                # managed Postgres requires TLS

objectStorage:
  external:
    enabled: true
    s3Endpoint: "https://ewr1.vultrobjects.com"
    region: "ewr1"
    bucket: orbital-media

backups:
  enabled: true
  bucket: orbital-backups
  endpoint: "https://ewr1.vultrobjects.com"
  keepDays: 30
```

If pgvector wasn't available in Part 3, drop the whole `postgres:` block
— the chart falls back to its own in-cluster PostgreSQL, which has it.

---

## Part 9 — Install

```bash
helm install orbital oci://cr.calltheory.com/orbital/charts/orbital \
  --namespace orbital \
  -f helm/orbital/values-vultr-vke.yaml \
  -f orbital-values.yaml
```

Helm will appear to hang for a minute — it is waiting for the migration
job, which runs the schema and seeds the reference data on a first
install. That is expected.

**If the install fails, read the migration job first.** It is the most
likely thing to have failed and it says why:

```bash
kubectl logs -n orbital job/orbital-orbital-migrate
```

---

## Part 10 — Check it came up

```bash
kubectl get pods -n orbital
```

**Every pod should reach `Running`**, with the migrate job showing
`Completed`. Give it two or three minutes.

If something is stuck:

```bash
kubectl describe pod -n orbital <pod-name> | tail -30
```

| Symptom | Almost always |
|---|---|
| `ImagePullBackOff` | The Harbor secret (7a) — wrong name, or the `$` got eaten |
| `CrashLoopBackOff` on laravel | Can't reach the database — check host, port, and the DB firewall |
| Migrate job failed | Read its logs; usually pgvector or database credentials |
| Pod `Pending` forever | Not enough room on 3 nodes — scale the node plan up |

Then confirm the certificate issued:

```bash
kubectl get certificate -n orbital
```

`READY` should be `True`. If it stays `False`, DNS (Part 6) is the
usual cause.

---

## Part 11 — First login

Open `https://orbital.yourcompany.com`.

**There is no login yet.** A fresh install seeds roles, permissions and
reference data, but it does not invent an administrator — the seeder
that would only acts on `SUPER_ADMIN_EMAIL`/`SUPER_ADMIN_PASSWORD`,
which the chart deliberately never sets, because a password passed
through Helm values ends up in `helm get values` for anyone with cluster
read.

Create yours explicitly:

```bash
kubectl exec -n orbital deploy/orbital-orbital-laravel -- \
  php artisan orbital:make-admin \
    --name="Your Name" \
    --email=you@yourcompany.com \
    --password='<a strong password>'
```

> Pass all three flags. Without them the command prompts interactively,
> and `kubectl exec` without `-it` gives it no terminal to prompt on —
> it will simply hang.

Now log in. Once in, go to **Settings → Platform** and:

1. **AI Providers** — paste your Anthropic key. Nothing AI works
   without it.
2. **Error Reporting / Tracing** — optional, off by default.
3. Check **System → Status**. Cards for components you didn't deploy
   (Prometheus, Grafana, Tempo) read "not deployed" rather than red.

Then prove the backups work, before you need them:

```bash
kubectl exec -n orbital deploy/orbital-orbital-laravel -- \
  php artisan orbital:backup

kubectl exec -n orbital deploy/orbital-orbital-laravel -- \
  php artisan orbital:backup --list
```

An untested backup is a belief, not a plan.

---

## Part 12 — The SIP edge (calls)

Everything above gives you the web platform. **Telephony needs the edge
VMs**, which live outside the cluster: Kamailio as the SIP front door and
rtpengine for media, provisioned by the `orbital-setup` repo's
`terraform/vultr/` entrypoint.

Once those exist, tell the chart where Kamailio's control interface is
and upgrade:

```bash
helm upgrade orbital oci://cr.calltheory.com/orbital/charts/orbital \
  --namespace orbital \
  -f helm/orbital/values-vultr-vke.yaml \
  -f orbital-values.yaml \
  --set telephony.kamailio.jsonrpcUrl=http://<edge-vm-ip>:8090/jsonrpc
```

Point your carrier's DIDs at the edge VMs' floating IP, not at the
Kubernetes load balancer.

---

## Upgrading later

Same command, `upgrade` instead of `install`, with a new image tag:

```bash
helm upgrade orbital oci://cr.calltheory.com/orbital/charts/orbital \
  --namespace orbital \
  -f helm/orbital/values-vultr-vke.yaml \
  -f orbital-values.yaml \
  --set global.image.tag=0.2.0
```

Migrations run automatically **before** the new pods roll, so no pod
ever serves new code against an old schema. If a migration fails the
release fails and nothing rolls.

Roll back with `helm rollback orbital`. Note that this reverts the
application, **not** the database — a migration that has already applied
stays applied, which is why a restore path exists.

---

## Before you point real callers at this

- [ ] A backup has run and been listed successfully
- [ ] `BACKUP_ENCRYPTION_KEY` is stored outside the cluster
- [ ] `APP_KEY` is stored outside the cluster (restoring without it
      leaves platform settings unreadable — see
      [Backups](../admin/backups.md))
- [ ] Database firewall allows the cluster and nothing else
- [ ] Alertmanager has somewhere to deliver
      ([Observability](../admin/observability.md))
- [ ] Two-factor enforcement reviewed under **Settings → Security**
- [ ] The SIP edge exists and a test call completes

---

## See also

- [Backups](../admin/backups.md) — the restore path, and the APP_KEY trap
- [Observability](../admin/observability.md) — alerts, tracing, error reporting
- [High Availability](../admin/high-availability.md) — what the tiers do
- `helm/README.md` — every chart value, and the redundancy knobs
