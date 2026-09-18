# Installation

Orbital is [AGPL-3.0](https://www.gnu.org/licenses/agpl-3.0.html) and can be
self-hosted, for unlimited clients, seats, calls, and channels, at no cost.
Nothing is feature-gated.

There are two paths, and they are quite different.

| Path | For | Runs on |
|---|---|---|
| **Local development** | Evaluating, or working on the code | Docker Compose on one machine |
| **Production** | Serving real callers | Kubernetes, with the telephony edge on dedicated hosts |

## What Orbital needs

Orbital is not a single application — it is a platform stitched together
from several services, which is worth knowing before you start.

| Component | Does |
|---|---|
| **Laravel + PHP 8.4** | The admin panel, operator workspace, client portal, and API |
| **PostgreSQL 17** with pgvector | Everything durable. The `vector` extension is required |
| **Valkey** (or Redis) | Sessions, cache, and the job queue |
| **Asterisk** | SIP call handling |
| **LiveKit** | Real-time media and the AI voice agents |
| **A Python worker** | Runs the AI agents against LiveKit |
| **S3-compatible storage** | Recordings, attachments, and media |
| **An SMTP shim** | Accepts inbound mail on your clients' behalf |

**pgvector is not optional.** The knowledge-base feature creates the
`vector` extension during migration, so a PostgreSQL without it fails to
install. If you are using a managed database, confirm the extension is
available before you begin.

## Local development

### Prerequisites

- Docker and Docker Compose
- PHP 8.4+ and Composer
- Node.js 20+ with pnpm

### Quick start

```bash
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
pnpm install
php artisan migrate --seed
pnpm run dev
```

Then create yourself an administrator — a fresh install has no login:

```bash
php artisan orbital:make-admin
```

See [First Login](first-login.md) for what to do next.

### What seeding creates

Every install — production included — seeds the reference libraries the
platform is built out of: roles and the permission catalog, the intake goal
library, persona templates, queue strategy templates, hold music classes,
availability and logout reasons, and the skill catalog. These are starting
vocabulary, not sample data, and you are meant to edit them.

**A local install seeds more.** Demo clients, operators, and contacts, plus
three template tenants showing the common answering-service shapes — voicemail
only, live operators, and live operators with AI overflow. Each is a real
working configuration with its own queues, routing, and personas.

**Both of those are `local` only.** A production install gets no accounts and
no sample clients, so do not plan an onboarding process around cloning a
template tenant on a production box. Bringing them to production installs is on
the [Roadmap](../roadmap.md).

Do not build anything real on top of the demo accounts. They exist to be
deleted.

The seeded local logins:

| Account | Email | Password | Panel |
|---|---|---|---|
| Demo Operator | `demo-operator@orbital.test` | `password` | Operator |
| Demo Supervisor | `demo-supervisor@orbital.test` | `password` | Operator |
| Demo Customer Contact | `contact@democustomer.test` | `password` | Portal |
| Acme Corp Contact | `contact@acmecorp.test` | `password` | Portal |

Your own super-admin is not in that table because it is not seeded — you create
it with `orbital:make-admin`, and choose the password yourself. That is the
whole reason these shared passwords are safe to print: they only ever exist on
a `local` install, and nothing in production is created with a password
somebody could read in documentation.

## Production

A production deployment has two halves.

**In Kubernetes:** the application, queue workers, real-time server,
Asterisk, LiveKit, and the AI worker, installed with the Helm chart. Point
the database and object storage at managed services where you can — that is
what gets you replication and provider-run backups without operating a
database yourself.

**On dedicated hosts:** the SIP edge — Kamailio as the front door and
rtpengine for media. This runs outside the cluster deliberately. Media on
Kubernetes means large UDP port ranges and NAT traversal that Kubernetes
networking makes harder rather than easier, and there is no benefit that
repays the difficulty.

Before serving real callers:

- **Turn on [backups](../admin/backups.md).** High availability covers a
  dead node and replication covers a dead disk. Neither covers a dropped
  table or a bad migration, because those replicate faithfully to every
  copy.
- **Give [alerting](../admin/observability.md) somewhere to deliver.** A
  platform that fails quietly at 3am is one you find out about from your
  client.
- **Review [two-factor enforcement](../admin/security.md).**
- **Place a test call.** It is the only check that covers the whole path.

## Running without the public internet

Orbital is built to run on infrastructure you control, including
infrastructure that cannot reach the internet. That shapes a few things you
might otherwise expect to be different:

- **No CDN references.** Every stylesheet, script, and font is bundled locally
  by the asset build. A browser loading the admin panel makes no third-party
  requests.
- **Local embeddings.** Knowledge base indexing can run against a local Ollama
  model rather than a hosted embedding API, so per-client documents never leave
  your infrastructure.
- **Local object storage.** SeaweedFS presents an S3 API, so recordings, media,
  and attachments can stay on your own disks.
- **Local transcription.** Voicemail can be transcribed by a local Whisper
  model instead of a cloud provider. See
  [Call Recording & Voicemail](../admin/recording.md).
- **No per-call or per-minute fees.** There is no vendor metering your traffic.

**What still needs the internet:** your SIP carrier, and any hosted AI provider
you choose to use for the conversational agents. A fully air-gapped deployment
is possible for everything else, but a voice agent driven by a hosted model is
not one of the things that can be.

## Getting the software

The source is available under the AGPL, so you can build the images
yourself. Call Theory customers receive prebuilt images and the
infrastructure-as-code tooling for their deployment as part of their
hosting or support arrangement.

## See also

- [First Login](first-login.md) — setting up a fresh installation
- [How Orbital Works](../concepts.md) — the model before the configuration
- [High Availability](../admin/high-availability.md) — running it redundantly
