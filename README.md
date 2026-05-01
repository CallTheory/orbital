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

## Stack

- **Backend** — Laravel 12, PHP 8.4, Filament 5, Livewire 4
- **Database** — PostgreSQL 17 with pgvector for knowledge-base embeddings
- **Cache / queues / sessions** — Valkey (Redis-compatible)
- **Telephony** — Asterisk 22 (SIP, MixMonitor recording, AMI)
- **Media / AI voice** — LiveKit server + LiveKit SIP bridge + a Python
  agent worker (Anthropic / OpenAI / ElevenLabs)
- **Object storage** — MinIO (S3-compatible) for call recordings and
  tenant assets
- **Observability** — Prometheus, Loki, Promtail, Grafana
- **Frontend** — Tailwind 4, Alpine.js, SIP.js (WebRTC softphone),
  WaveSurfer.js (recording playback) — all bundled via Vite, no public
  CDNs (offline-first is a hard requirement)
- **Dev environment** — Laravel Sail / Docker Compose

## Quick start

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

## Deploying

Infrastructure code (Terraform, Ansible, cloud-init, customer install
scripts) lives in a separate **public** repo:
**https://git.calltheory.com/calltheory/orbital-setup**

Clone that repo to provision a Vultr or on-prem K3s cluster. Its install
scripts pull this chart from Harbor at `oci://cr.calltheory.com/orbital/charts/orbital`,
so the deployment never needs read access to this private app repo.

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

Proprietary. All rights reserved.
