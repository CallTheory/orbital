# Orbital — TODO

Pre-launch punch list. Items that can wait until we're closer to
production but MUST NOT ship without (critical section) or that
are tracked so they don't fall through the cracks (deferred
features section).

## Critical — before production

- [ ] **Observability stack — metrics + dashboards out of the box.**
      The plumbing already exists (Prometheus, Loki, Promtail, Grafana
      running with provisioned datasources) but nothing is actually
      scraping yet and no dashboards ship provisioned. Phases:
      - **Phase A (~1.5h):** LiveKit built-in Prometheus endpoint
        (flip it on in `livekit.yaml`), MinIO `/minio/v2/metrics/cluster`,
        `postgres_exporter` sidecar, `redis_exporter` sidecar (Valkey
        speaks Redis protocol), scrape configs for all four. One
        starter **Infrastructure** dashboard provisioned.
      - **Phase B (~2h):** Laravel `/metrics` endpoint via
        `spatie/laravel-prometheus` (request rate, response time
        histogram, queue depth, per-tenant call counts from `CallLog`,
        per-tenant email counts from `EmailMessage`). **Orbital App**
        dashboard.
      - **Phase C (~2h–1 day):** Asterisk metrics. **Lean: write our
        own `orbital:collect-telephony-metrics` scheduled command**
        that uses `AsteriskAmiService` to poll AMI every 15s and
        push to Prometheus Pushgateway — fewer moving parts than
        vendoring the community `asterisk_exporter` image, reuses
        infra we already own. **Telephony** dashboard.
      - **Phase D (~1h):** LiveKit / AI dashboard on top of the
        metrics from Phase A (active rooms, agent worker jobs,
        LLM latency p50/p95, TTS generation rate).
      - Also: fix Asterisk `full` log to write to stdout in
        `logger.conf` so Promtail picks it up automatically instead
        of needing a file scrape. Other container logs already flow.
      - Do this **before** the i18n pass — there's still a lot of
        churn coming and having metrics to spot regressions earlier
        is worth more than translated labels right now.

- [ ] **2FA enforcement — Phases 2–4 (TOTP shipped, rest pending).**
      Phase 1 (TOTP enrollment on `/{panel}/security`) is live. The
      remaining phases from the original 2FA design:
      - **Phase 2 (~2d):** WebAuthn / passkeys / hardware keys via
        `laragear/webauthn`. First-class passkey login **plus** 2nd-
        factor hardware key support from the same credentials table.
        Ceremony is browser↔server, no third-party service.
      - **Phase 3 (~1d):** Email OTP as a fallback challenge. Weakest
        of the three (if an attacker has email access they have the
        account) but required for users who won't adopt an
        authenticator. Custom Fortify challenge action + rate-limited
        form + Valkey-backed code store with short TTL.
      - **Phase 4 (~0.5d):** `RequireTwoFactor` middleware on all
        three panels that redirects un-enrolled users to
        `/profile/security` once their grace window expires. New
        `two_factor_grace_days` column on `teams` (0–30, default 7).
        Countdown banner via Filament `BODY_START` render hook
        showing "N days left to enable two-factor authentication"
        that flips red in the last 48 hours and disappears the
        moment any method is enrolled.
      - Scope confirmed from earlier conversation: **required for
        everyone** — platform staff AND tenant portal users — with
        the per-tenant grace window as the only configurable knob.

- [ ] **Full i18n pass.** Wrap every user-facing string in the app
      (Filament resource labels, form labels, table column labels,
      notifications, blade copy) in `__('key')` and author
      `lang/{locale}/*.php` translation files for every locale we
      surface on the Security/Profile page's language select
      (currently en, es, fr, fr_CA, de, nl, pt, it, da, sv, no, fi).
      Filament itself already ships ~30 translated locales for its
      chrome strings — we just need to cover our own labels.
      Estimated ~2–3 days of mechanical work plus translator cost.
      **Defer until the English copy is locked down** so we don't
      pay to translate strings that will change. Also decide at that
      point whether to add Polish / Ukrainian / Russian / Quebec
      French beyond the scaffolding list.

## Runtime config not yet set

- [ ] **Anthropic API key** in Platform Settings → AI Providers.
      `ProcessEmailWithAgentJob` is fully wired (compiles flow, builds
      prompt, projects thread history, calls Anthropic, hands reply
      to OutboundReplyService) but currently fails with "Anthropic
      API key not configured" because no key is set in the dev
      environment. Set it and AI-routed email threads will round-trip
      end-to-end without any code changes.

## Deferred features — tracked so they don't fall through the cracks

Not blockers. Ordinary backlog — reach for when the critical list
is clear or when a specific item becomes painful enough to justify
the work.

### Inbound email follow-ons (Phases 1–4 shipped)

- [ ] **Tenant-custom inbound domains.** Let tenants point their own
      domain's MX at our Haraka instance so `support@customer.com`
      lands here instead of `100001@inbound.orbital.test`. Requires
      per-tenant domain config + DNS coordination + a
      `tenant_domains` lookup table in Haraka's `rcpt` hook.
- [ ] **Rich-text reply composer** in the operator inbox. Currently
      a plain textarea; the HTML body rendering and threading
      infrastructure supports rich replies, only the UI is missing.
- [ ] **Attachment inline previews** for images and PDFs. Right now
      operators can only download attachments; images and PDFs
      should render inline via pre-signed MinIO URLs.
- [ ] **Postgres full-text search** on threads (tsvector + GIN index
      on subject_root + body_text). Current implementation uses
      ILIKE which works fine until volume grows past a few thousand
      threads per tenant.
- [ ] **Anti-spam + DKIM/SPF/DMARC verification at Haraka** for when
      we open to the public internet. Not needed for internal/
      scoped-domain routing but required before accepting arbitrary
      inbound mail.
- [ ] **Bounce handling** for outbound replies. When the customer's
      mail server rejects our reply, we need to notice and surface
      it to the operator instead of silently failing.
- [ ] **Per-tenant email volume dashboard widget** on `/admin`
      showing thread count + mean-time-to-reply over the last 7
      days. Waiting on real traffic to be meaningful; ties into the
      observability stack above.
- [ ] **Thread merging** — admin action to combine two threads about
      the same topic into one. Rare case; manual-only is fine.
- [ ] **IMAP/POP3 client** for pulling from tenants' existing
      mailboxes (different from running our own MX). Separate
      feature, different shape.

### Other

- [ ] **Horizon metrics + alerting.** Once the observability stack
      lands, wire Horizon queue depth + failure rate into the
      Orbital App dashboard so a stalled `inbound-mail` queue or a
      flood of failed jobs is visible at a glance.
- [ ] **Multi-tenant portal access for contacts + shared directories.**
      Today a contact's "Grant portal access" action links
      `contacts.user_id` to a single User that lives under one
      tenant's `tenant_user` role. In reality a contact might legitimately
      belong to multiple tenants (e.g. a law firm that's also
      on a partner escalation list), and shared-list entries
      don't belong to any single tenant at all. The fix is a
      many-to-many `contact_portal_access` pivot (contact_id +
      team_id + role) plus a panel switcher in the portal so a
      signed-in contact can toggle between tenants they have
      access to. Touches: `Contact` model, portal nav, portal
      RequestContext, the shared-list entries editor (currently
      skips the grant-access action entirely because it can't
      answer "which tenant?"). ~half-day refactor, plan before
      touching.

## Big items — deliberately scheduled late

Large, architecture-level features that touch every layer of the
stack. Called out separately so they're not confused with ordinary
backlog — these each deserve their own dedicated push with full
planning + testing. User preference: **do these last, after the
core product is stable**. Schedule ahead of any production cutover.

### HA / DR / BC — active-active horizontal scaling + DR site

**Goal.** Run every layer of the stack duplicated in an active-
active configuration so we can take one node offline for
maintenance, patching, or cert renewal and bring it back online
with zero customer-facing downtime. Plus a separate **DR site**
mode: a single-stack instance at a different location that stays
synced from the primary and can be promoted if the primary site
is completely lost. Normal operation (~99% of the time) runs off
the active-active HA stack; the DR site only takes traffic during
testing drills (twice a year) or an actual disaster.

**Layer-by-layer approach to active-active:**

- **SIP front door — Kamailio as the call router.** Kamailio sits
  in front of Asterisk and acts as an SIP-aware dispatcher. Its
  `dispatcher` module health-checks backend Asterisk nodes and
  routes calls to whichever is alive; two Kamailios behind a VRRP
  floating IP (keepalived) give us a redundant front door.
  Operators / carriers point at the Kamailio floating IP and
  never talk to Asterisk directly. This is the piece that makes
  taking one Asterisk offline for maintenance invisible to
  end users.

- **Asterisk.** Two+ Asterisk instances behind Kamailio, both
  reading from the shared ARA tables in Postgres (already done
  in the ARA refactor). Shared realtime DB means both Asterisks
  see the same endpoint/queue/route state with no sync layer
  needed. Call recording writes to the same MinIO bucket. Live
  calls are pinned to whichever Asterisk the SIP leg terminates
  on, so a failover mid-call still drops the call — that's
  acceptable; the goal is availability for *new* calls, not
  mid-call portability.

- **Laravel app (`orbital.test`).** Already stateless — sessions
  in Valkey, uploads in MinIO, DB external. Run N replicas
  behind a web load balancer (HAProxy or Nginx). Horizon
  supervisors can run multiple instances against the same
  Valkey with no coordination needed.

- **PostgreSQL.** Primary + streaming replica(s) with automated
  failover via Patroni + etcd (or PostgreSQL 16+ native
  replication + a simple failover script). Reads can go to the
  replica for scale; writes always go to the primary. For DR:
  WAL shipping to the DR site's standby.

- **Valkey.** Sentinel for HA (simpler than Valkey Cluster for
  our scale). One primary + replicas with automatic failover.
  Horizon jobs survive a Valkey primary failover if replicas are
  caught up; a brief interruption is acceptable.

- **LiveKit.** LiveKit supports distributed mode via Redis for
  room-state coordination. Two LiveKit nodes behind a TCP load
  balancer; rooms can move between nodes automatically.

- **Agent worker (Python).** Stateless, scale horizontally.
  Jobs come from LiveKit dispatch, so N workers just work.

- **MinIO.** MinIO distributed mode natively supports quorum-
  based replication across nodes. For DR: MinIO Site Replication
  (active-passive) to the DR site.

- **Haraka (inbound email).** Stateless; multiple instances
  behind a DNS round-robin or TCP load balancer on port 25.
  Each instance validates RCPT via Laravel's already-shared
  webhook.

- **Grafana / Prometheus / Loki.** Can run as a single instance
  per site without impacting customer-facing availability; the
  observability stack being briefly unavailable doesn't stop
  calls or email.

**DR site mode:**

- Single-stack deployment in a different region / datacenter.
  Same compose/deploy artifacts as the primary, different
  runtime config.
- **Data sync from primary:**
  - Postgres: streaming WAL replication to a read-only standby
    that can be promoted on demand
  - MinIO: Site Replication (active-passive) for raw email
    blobs, attachments, call recordings, TTS prompts
  - Tenant-generated config: already derived from DB, so the
    replicated DB covers it
  - Generated Asterisk dialplan files: regenerated from DB on
    promotion via `orbital:generate-config`
  - Valkey (sessions, cache, queue state): **not replicated** —
    DR promotion flushes queues and logs operators out. Critical
    jobs (outbound email, call recording uploads) need to be
    idempotent-retry-safe so re-queueing on the DR side is
    harmless.
- **Failover UX:** operators log in at a different URL
  (e.g. `dr.orbital.example`) during a real DR event. Softphones
  and browser clients need to know the DR URL ahead of time —
  publish it in the operator docs.
- **Testing cadence:** automated drills monthly (promote the DR
  standby, verify login + basic call + basic email, demote),
  full cutover drill twice a year with real traffic routed
  through DR for a short window.

**Admin controls:**

- A "node health + maintenance mode" admin page that shows which
  backend nodes are currently registered with Kamailio / the
  web LB / the MinIO cluster, and lets a super-admin mark a
  node as draining so new traffic stops hitting it. Combine with
  the existing system health dashboard.
- A "DR failover" admin action (heavily gated, confirmation +
  typed secret) that triggers the promote sequence. Runbook
  exists as a markdown doc inside the admin panel.

**Before tackling this:** the core product must be stable and
settled enough that we're not redesigning its data shape every
sprint. HA layers get much harder when the schema is still
moving.

### Configuration + data backups

**Goal.** Scheduled, admin-configurable backups of the full
Orbital state to an off-site destination so we can survive a
catastrophic data loss even without a DR site. Complementary to
HA/DR — HA keeps us up during a node failure, DR keeps us up
during a site failure, backups keep us recoverable from a logic
failure (bad migration, admin mistake, compromised account that
wipes tables).

**What to back up:**

- **Postgres dump** — full schema + data via `pg_dump` or
  `pg_basebackup`. Compressed. This is the biggest and most
  critical artifact.
- **MinIO content** — all buckets: raw inbound email
  (`mail-raw`), email attachments (`mail-attachments`), call
  recordings, TTS disclosure prompts, tenant uploads. Either as
  a full sync or incremental.
- **Generated Asterisk config** — `docker/asterisk/config/` if
  we keep anything non-derivable there (post-ARA most of this is
  regenerated from DB, but the few baked config files should be
  snapshotted).
- **Tenant-level config exports** — per-tenant JSON exports of
  routing rules, intake goals, flows, personas, contacts, etc.
  so a single tenant can be restored independently without
  touching the rest of the system.
- **Application `.env`** — runtime config (API keys, feature
  flags, custom secrets). Stored encrypted since it contains
  credentials.

**Destinations (admin-configurable):**

- S3-compatible cloud storage (AWS S3, Backblaze B2, Cloudflare
  R2, DigitalOcean Spaces, Wasabi, etc.) — configured via
  standard AWS SDK env vars so any S3-API-compatible endpoint
  works out of the box
- SFTP to a customer-owned server
- Plain local disk mount (for sites with their own off-site
  sync mechanism, e.g. Borg / restic / Veeam)
- Multiple destinations simultaneously — belt and suspenders

**Operational requirements:**

- **Schedule:** admin-configurable cron expression. Default
  daily at 02:00 local.
- **Retention policy:** admin-configurable. Default keep 7
  daily + 4 weekly + 12 monthly snapshots.
- **Encryption at rest:** backups encrypted with a user-supplied
  passphrase before upload. Decryption only happens during
  restore. Losing the passphrase means losing the backup — warn
  loudly on setup.
- **Integrity check:** every backup run writes a manifest with
  file hashes and a test-restore hash so a corrupted upload is
  detectable.
- **Notification:** Filament notification + Slack / email alert
  on backup success/failure. Silent failure is the worst case.

**Admin UI:**

- Platform Settings → Backups page with:
  - Destination list (add / edit / test)
  - Schedule config + enable/disable toggle
  - Retention policy config
  - Encryption passphrase (set once, stored hashed)
  - **Run now** button
  - History table of recent runs (status, size, duration, errors)
- Restore flow is a CLI-first operation (`orbital:restore` command)
  because the UI may not be available when you need it most.
  The admin UI surfaces a "last known restore point" read-only
  summary.

**Note on ordering:** a minimal `orbital:backup` artisan command
that dumps DB + MinIO to a hard-coded S3 bucket on a schedule is
low-effort, high-value, and could ship well before the full
admin-configurable UI. Consider shipping the CLI path early even
if the UI waits for the HA/DR push — it's the difference between
"we're recoverable" and "we're not".
