# Environment configuration

Orbital reads most of its runtime config from `.env`. A curated subset of
those values is ALSO editable from the admin panel under **System →
Settings** — the DB override wins when both are populated.

This document covers the rules for deciding which side each setting lives
on, and the `.env`-only settings worth knowing about.

---

## How settings reach the running app

1. **Laravel boot** loads `.env` into `$_ENV` via `vlucas/phpdotenv`.
2. **Config files** (`config/*.php`) read those via `env('FOO', default)`
   and merge the results into the Laravel config container.
3. **`RuntimeConfigOverrideProvider`** runs late in boot, reads every
   row of `platform_settings`, and calls `config()->set(...)` for each
   registered key. This is the point where the admin-UI overrides take
   effect.
4. **Long-running processes** (Horizon workers, Reverb daemon, LiveKit
   agent worker) resolve their config ONCE at boot and cache it. They
   do not see admin-UI changes until restarted.

This layering is why some admin-UI settings carry a **"Requires
restart"** badge — the HTTP request path picks up changes instantly, but
the long-lived process tier doesn't.

---

## What lives in the admin UI

Everything surfaced under **System → Settings** is defined in
`app/Services/Settings/SettingsRegistry.php`. Adding a setting to the
admin UI is a one-liner registry entry, covered in that class's
docblock.

The rule of thumb: a setting lives in the admin UI when the operator
might need to change it at runtime and **Laravel alone owns the value**.
If rotating a value requires coordinated changes to docker-compose, DNS,
PKI, or a sibling container's env, the admin UI helper text spells that
out but the operator is still responsible for the external half.

---

## What's deliberately `.env`-only (and why)

### Boot-critical infrastructure (foot-guns)

Bricking these via the UI would prevent you from logging in to the UI
to fix them:

| Var | Reason |
|---|---|
| `APP_KEY` | Rotated via `php artisan key:generate`. Changing it invalidates every encrypted value in the DB — sessions, cookies, Passport tokens. Never put a text field on this. |
| `APP_ENV` | Switches Laravel's environment mode; affects cache paths, debug behavior, trusted proxy lists, and a dozen packages' boot logic. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | The admin UI reads and writes to this database. Change it via UI, save fails, page reloads against the new config, can't find the database, UI is gone. |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Sessions, cache, queues, Horizon, Reverb scaling, LiveKit room state all depend on this. Same bricking argument as the DB. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | Swapping drivers mid-deployment invalidates the current session/cache/queue backend. Should be a deliberate .env + restart operation. |

### Container-time / compose-only

These are consumed by docker-compose at container start, not by
Laravel. Editing them in a running app has zero effect:

| Var | Owner | Notes |
|---|---|---|
| `APP_SERVICE`, `APP_PORT` | compose | Which service name and host port the app container binds to. |
| `WWWUSER`, `WWWGROUP` | compose | UID/GID for the `www-data` shadow user inside containers. Pin to the host UID so bind mounts don't get permission-wedged. |
| `FORWARD_*_PORT` | compose | Port mappings from host → container for DB/Redis/Mailpit/etc. during local dev. |
| `VITE_PORT`, `VITE_APP_NAME`, `VITE_REVERB_*` | Vite | Baked into the browser bundle at `pnpm run build` time. Changing requires a rebuild. |

### Tied to external systems (sibling-container parity)

Values that must stay identical across Laravel and another service's
own env. These ARE surfaced in the admin UI (because Laravel uses
them at runtime) but the helper text explicitly flags the parity
requirement:

| Var | Paired with | Surfaced in UI |
|---|---|---|
| `INBOUND_MAIL_TOKEN` | Haraka service env | Yes — System → Settings → Inbound Mail |
| `INBOUND_MAIL_DOMAIN` | DNS MX record | Yes — System → Settings → Inbound Mail |
| `ICECAST_ADMIN_PASSWORD` | Icecast service env | Yes — System → Settings → Icecast |
| `VOICEMAIL_WEBHOOK_TOKEN` | Asterisk service env (`notify-voicemail.sh`) | No — too niche to surface |

### Tied to container internals (no Laravel consumer)

These are read inside other containers; Laravel never sees them. No
admin UI because there's nothing Laravel could do with the value:

| Var | Consumed by | Purpose |
|---|---|---|
| `ICECAST_SOURCE_PASSWORD` | Icecast + Asterisk MOH publisher | Authenticates the Asterisk hold-music stream to Icecast. |
| `ICECAST_PASSWORD` | Icecast internal | Privileged source/relay operations. |
| `ICECAST_RELAY_PASSWORD` | Icecast | Only relevant in master-to-relay HA topology. |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Reverb daemon + JS client (Vite bake) | App credential triple. Generated per install via `php artisan reverb:install`. |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | Reverb daemon | What the Reverb process binds to inside the container. |
| `REVERB_SCALING_ENABLED`, `REVERB_SCALING_CHANNEL` | Reverb daemon | Valkey pub/sub fan-out between Reverb nodes in HA. |
| `WHISPER_MODELS` | whisper-local image build | Comma-separated ggml models baked into the transcription container. Build-time only. |

### Tied to DNS, PKI, or infrastructure outside docker

Managed through dedicated admin surfaces or left in `.env` because the
operation is one-shot during provisioning:

| Var | Managed by |
|---|---|
| `ACME_ENABLED`, `ACME_DOMAIN`, `ACME_DNS_PROVIDER`, `ACME_WEBHOOK_TOKEN` | **System → TLS Certificates** page |
| `TLS_CERT_PATH`, `TLS_KEY_PATH` | Baked into nginx/Asterisk configs; changing these is a container-topology change |
| `GRAFANA_INTERNAL_URL`, `GRAFANA_PROXY_TRUST_TOKEN` | `SsoSecretsBootstrapper` populates and rotates on first-run / restart |
| `KAMAILIO_ENABLED`, `KAMAILIO_JSONRPC_URL`, `KAMAILIO_JSONRPC_URLS` | Cluster-topology setting — adding/removing Kamailio nodes is a deployment event, not a runtime toggle |

---

## Rotating sibling-container secrets

When rotating a value that lives in both `.env` and another service's
env (the sibling-container parity list above):

1. Generate the new value: `openssl rand -hex 24`.
2. Paste into `.env` AND into the sibling service's env block in
   `docker-compose.yml`.
3. Save via the admin UI (if surfaced) so the Laravel-side DB
   override matches.
4. Restart the affected container: `./vendor/bin/sail restart <service>`.
5. Hit the relevant flow end-to-end (send a test email, play hold
   music, etc.) to confirm the new value is the one in use.

Skipping step 3 means the .env value gets shadowed by a stale DB
override on the next request path — the dreaded "I updated .env but
nothing changed" symptom.

---

## See also

- `app/Services/Settings/SettingsRegistry.php` — canonical list of UI-surfaced settings
- `app/Providers/RuntimeConfigOverrideProvider.php` — boot-time override application
- `app/Services/Settings/ServiceRestartCatalog.php` — which slugs the "Restart affected services" button knows how to restart
