# Orbital

Orbital is a multi-tenant answering-service / virtual-receptionist platform.
It is installable software: a call center or answering service (the
"platform operator") runs Orbital to take calls on behalf of their
customers ("tenants"). AI voice agents and platform staff share the same
call-handling surface — the same scripts, the same recordings, the same
call history — so a call can hand off between agent and operator mid-
conversation without either side losing context.

Tenants get a read-only portal to see messages, recent calls, and
recordings taken on their behalf. They never configure telephony
infrastructure themselves; the platform operator owns trunks, extensions,
routing, and queues.

It is a **multi-channel** inbound platform: voice, inbound email, web
chat, and SMS/MMS all route into the same operator surface, share the
same claim/release semantics, and are driven by the same compiled
orchestrations — so a client's script is authored once and answers on
whichever channel the customer used.

Orbital is **free software under the AGPL-3.0**. Self-hosting is free
forever with no feature gates and no seat counting — see
[Licensing](#license).

## Stack

- **Backend** — Laravel 12, PHP 8.4, Filament 5, Livewire 4
- **Database** — PostgreSQL 17 with pgvector for knowledge-base embeddings
- **Cache / queues / sessions** — Valkey (Redis-compatible)
- **Telephony** — Asterisk 22 (SIP, MixMonitor recording, AMI)
- **Messaging** — SMS/MMS via a pluggable provider contract (Twilio
  driver ships; see `docs/admin/messaging.md`)
- **Media / AI voice** — LiveKit server + LiveKit SIP bridge + a Python
  agent worker (Anthropic / OpenAI / ElevenLabs)
- **Object storage** — MinIO (S3-compatible) for call recordings and
  tenant assets
- **Observability** — Prometheus, Pushgateway, Loki, Promtail, Grafana
  (four dashboards ship provisioned; app metrics on `/metrics`, platform
  and telephony metrics pushed by `orbital:collect-metrics`)
- **Frontend** — Tailwind 4, Alpine.js, SIP.js (WebRTC softphone),
  WaveSurfer.js (recording playback) — all bundled via Vite, no public
  CDNs (offline-first is a hard requirement)
- **Dev environment** — Laravel Sail / Docker Compose

## Quick start

This is the **development** environment (Laravel Sail). For the full
three-environment story — dev, staging, production — see
[Environments](#environments) below.

```bash
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
pnpm install
php artisan migrate --seed
pnpm run dev
```

The default seeders create a super-admin (`admin@orbital.test` / `password`),
a platform operator + supervisor, and a demo tenant. See
`database/seeders/DemoTenantSeeder.php` for the full list.

Then visit **http://localhost/login**. Users are routed to the right
Filament panel based on their role:

- `super_admin` → `/admin` — platform configuration, tenant management
- Platform staff (`operator`, `supervisor`, …) → `/operator` — softphone,
  intake flow viewer, call queue
- Tenant users → `/portal` — read-only activity summary and call history

## Common commands

| Command                                     | What it does                              |
| -------------------------------------------- | ------------------------------------------ |
| `composer dev`                               | Server + queue worker + log viewer + Vite |
| `./vendor/bin/sail up -d`                    | Bring up the Docker stack                  |
| `./vendor/bin/sail artisan orbital:bootstrap`| Idempotent install of external services    |
| `./vendor/bin/sail artisan orbital:status`   | System health snapshot from the CLI        |
| `./vendor/bin/sail artisan orbital:generate-config` | Regenerate Asterisk configs from DB |
| `./vendor/bin/sail artisan orbital:render-disclosures` | Render recording-disclosure TTS prompts |
| `php artisan test`                           | Run the test suite                         |
| `vendor/bin/pint`                            | Fix PHP code style                         |
| `pnpm run build`                             | Production Vite build                      |

## Layout

- `app/Filament/` — admin / operator / portal panel resources and pages
- `app/Services/Telephony/` — Asterisk AMI client, config generation,
  call-recording policy resolver
- `app/Services/Health/` — dashboard health probes
- `app/Services/Bootstrap/` — idempotent "install external services" layer
- `app/Livewire/` — softphone, compiled-flow viewer, chat console
- `agent-worker/` — Python LiveKit agent worker
- `docker/asterisk/` — Asterisk 22 Dockerfile, baked configs, entrypoint
- `docker/livekit/` — LiveKit server + SIP bridge configs
- `helm/orbital/` — Helm chart packaged on tag push to Harbor OCI
- `local/` — k3d helpers for chart iteration
- `scripts/build-and-push.sh` — local image build + push to Harbor
- `resources/views/asterisk/` — Blade templates that render
  `extensions.conf`, `pjsip_generated.conf`, etc.

## Environments

Orbital runs in three environments. They share one codebase and one Helm
chart; what changes is where they run and how you push updates.

| Environment    | Runs on                              | Bring up / update                          |
| -------------- | ------------------------------------ | ------------------------------------------- |
| Development    | Laravel Sail (docker-compose), local | `./vendor/bin/sail up -d` + `composer dev`  |
| Staging        | k3d (K3s-in-Docker), local           | `./local/k3d-up.sh`, then `./local/k3d-build.sh` |
| Production     | Vultr VKE + edge VMs, off Harbor     | `scripts/build-and-push.sh` + `helm install`     |

### 1. Development — Laravel Sail

Everyday feature work. Fast, hot-reloading, no Kubernetes. This is the
[Quick start](#quick-start) above.

```bash
./vendor/bin/sail up -d      # Postgres, Valkey, Asterisk, LiveKit, MinIO
pnpm run dev                 # Vite dev server with HMR
```

Reach it at **http://localhost/login**. Edits to PHP/Blade/JS are live
immediately — no rebuild. Use this for all app logic, UI, and migration
work. Reach for staging only when you touch `helm/orbital/` or need to
validate real Kubernetes behavior (probes, multi-pod scheduling, the
object-storage config handoff, the SIP edge).

### 2. Staging — local k3d cluster

A production-shaped Kubernetes deployment on your own machine: same Helm
chart, same topology (1 server + 2 agents, ingress, RWO storage,
in-cluster Postgres/Valkey/SeaweedFS) as a real single-node install. Use
it to validate a change under real k8s before it ever touches the cloud.
Full details in [`local/README.md`](local/README.md).

**First bring-up** (creates the cluster, namespace, placeholder secrets,
and installs the chart):

```bash
./local/k3d-up.sh
echo "127.0.0.1 orbital.localhost" | sudo tee -a /etc/hosts   # once
open http://orbital.localhost:8080
```

**Redeploy after you change code** — this is the one command that pulls
your working tree into staging. It builds first-party images under a
unique tag, pushes them to the cluster registry, and rolls the release:

```bash
./local/k3d-build.sh                 # rebuild + redeploy all images
./local/k3d-build.sh laravel         # only the laravel image (others keep their tag)
```

The unique-tag-per-build is deliberate: reusing `:dev` makes `helm
upgrade` see no change and silently keep the old pods running (the
"green but running old code" trap). Every `k3d-build.sh` run forces a
real rollout.

**Optional — real SIP call path.** To place actual calls against the
cluster, bring up the off-cluster Kamailio + rtpengine edge and deploy
with the edge overlay:

```bash
./local/edge-up.sh          # Kamailio + rtpengine on the k3d docker network
./local/edge-register.sh    # seed the edge/backend rows so health + reload work
EDGE=1 ./local/k3d-build.sh # redeploys WITH the edge wiring preserved
```

Use `EDGE=1` on every redeploy while the edge is up — a plain
`k3d-build.sh` resets Asterisk to the non-edge defaults and drops the
NodePort exposure mid-session.

**Tear down:** `./local/k3d-down.sh` (and `./local/edge-down.sh` if the
edge is up). Nothing persists.

### 3. Production — Vultr VKE

Real managed Kubernetes on Vultr, images pulled from the private Harbor
registry (`cr.calltheory.com`), SIP edge on dedicated VMs.

**Infrastructure comes first, from a different repo.** The cluster, the
Kamailio/rtpengine edge VMs, Traefik, and cert-manager are all
provisioned by **orbital-setup**
(https://git.calltheory.com/calltheory/orbital-setup,
`terraform/vultr/managed-vke/`). This app repo only supplies images and
the chart; it never provisions cloud infra.

Once orbital-setup has stood up the cluster and edge:

**a. Cut a release — CI builds, signs, and publishes to Harbor.** Push a
`vX.Y.Z` tag and the `.forgejo/workflows/application-tests.yml` pipeline
does the rest: the `publish-images` job builds and cosign-signs all four
images, and the `publish-helm` job lints, renders against every
`values-*.yaml`, packages the chart, and pushes + cosign-signs it to
`oci://cr.calltheory.com/orbital/charts/orbital` at that version.

```bash
git tag v0.1.0 && git push forgejo v0.1.0    # → CI publishes signed images + chart
```

(Manual fallback when you need images without cutting a tag —
`HARBOR_USERNAME='robot$ci' HARBOR_TOKEN='<token>' ./scripts/build-and-push.sh --tag=v0.1.0`.)

**b. Create the in-cluster secrets.** The images are public, so no pull
secret is needed (add one under `global.imagePullSecrets` only for a
private mirror). The
chart never renders secret material — you create it once per cluster,
following the recipes in
[`helm/orbital/templates/secrets.example.yaml`](helm/orbital/templates/secrets.example.yaml):

```bash
kubectl create namespace orbital

kubectl -n orbital create secret generic orbital-orbital-app-secrets \
  --from-literal=APP_KEY="base64:$(php artisan key:generate --show)" \
  --from-literal=DB_PASSWORD='<...>' \
  --from-literal=REDIS_PASSWORD='<...>' \
  --from-literal=REVERB_APP_SECRET='<...>' \
  --from-literal=AWS_ACCESS_KEY_ID='<...>' \
  --from-literal=AWS_SECRET_ACCESS_KEY='<...>' \
  --from-literal=LIVEKIT_API_KEY='<...>' \
  --from-literal=LIVEKIT_API_SECRET='<...>' \
  --from-literal=ASTERISK_AMI_SECRET='<...>' \
  --from-literal=ANTHROPIC_API_KEY='<...>' \
  --from-literal=OPENAI_API_KEY='<...>' \
  --from-literal=ELEVENLABS_API_KEY='<...>'
```

**c. Install the chart from Harbor OCI** with the Vultr VKE overlay. The
deployment host pulls the signed chart from Harbor — it never needs read
access to this private app repo (this is exactly how orbital-setup drives
it). Fill the per-deploy knobs — your domain, the released version, the
edge VMs' Kamailio endpoint, and a strong agent-worker token. The chart
deploys the images of its own release (`--version 0.1.0` → `:0.1.0`), so
there is no image tag to set:

```bash
helm install orbital oci://cr.calltheory.com/orbital/charts/orbital \
  --version 0.1.0 \
  --namespace orbital \
  -f ./helm/orbital/values-vultr-vke.yaml \
  --set global.domain=orbital.your-company.com \
  --set telephony.kamailio.jsonrpcUrl=http://<edge-vm-ip>:8090/jsonrpc \
  --set agentWorker.token="$(openssl rand -hex 32)"
```

The `-f values-vultr-vke.yaml` above uses your local checkout for the
overlay; the chart *templates* come from the OCI artifact. When deploying
from a host without this repo, inline the overlay values as `--set` flags
or keep a private per-customer values file. To iterate on the chart
itself before cutting a release, install from the local path
(`helm install orbital ./helm/orbital …`) instead of the OCI ref.

**d. Run migrations** (the image does not auto-migrate):

```bash
kubectl -n orbital exec deploy/orbital-orbital-laravel -- \
  php artisan migrate --force
```

**e. Point DNS at the Vultr LoadBalancer.** cert-manager then issues the
Let's Encrypt cert for your domain (the ACME challenge needs DNS live
first — that's why `ingress.tls.enabled` only matters after DNS resolves).

**f. Once observability + TLS are up**, trim `health.disabledComponents`
in `values-vultr-vke.yaml` (drop `grafana`/`prometheus`/`loki`/`promtail`
and `tls`) and `helm upgrade` so those dashboard cards go green instead
of showing "not deployed".

Ship a new version later by rebuilding at a new tag (step a) and
`helm upgrade … --version <new>`.

## Conventions

- All tenant-scoped models use the `BelongsToTeam` trait for automatic
  scoping.
- New PHP files use `declare(strict_types=1);`.
- Frontend dependencies go through `pnpm` and Vite. Never pull from a
  public CDN — the app has to function on a LAN with no public internet.
- Asterisk config is generated from the database via Blade templates,
  never hand-edited.
- Claude Code guidelines for this repo live in `CLAUDE.md`.

## License

Orbital is free software under the **GNU Affero General Public License,
version 3** ([`LICENSE`](LICENSE)).

Self-hosting is free forever, with no feature gates, no seat counting, and
no license key — unlimited clients, users, concurrent calls, and channels.
What Call Theory sells is managed hosting, support subscriptions for
self-hosters, and managed answering services. None of that is code withheld
from this repository. See [`LICENSING.md`](LICENSING.md).

If you modify Orbital and let others use it over a network, section 13 of
the AGPL asks you to offer them the corresponding source. Orbital does this
for you — the panel footers and `/source` link to the running version's
source — so point `ORBITAL_SOURCE_URL` at your repository when you fork.

Bundled third-party components and their licenses:
[`NOTICE`](NOTICE), [`docs/reference/third-party-licenses.md`](docs/reference/third-party-licenses.md).
The name is a trademark and is handled separately:
[`TRADEMARK.md`](TRADEMARK.md).

- Contributing: [`CONTRIBUTING.md`](CONTRIBUTING.md) · [`CLA.md`](CLA.md)
- Reporting a vulnerability: [`SECURITY.md`](SECURITY.md)
