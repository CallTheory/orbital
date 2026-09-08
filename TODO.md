# Orbital — TODO

Pre-launch punch list. Items that can wait until we're closer to
production but MUST NOT ship without (critical section) or that
are tracked so they don't fall through the cracks (deferred
features section).

## Before making the repository public

Orbital is now AGPL-3.0 (`LICENSE`, `LICENSING.md`) with the section 13
source offer wired into the product — panel footers, `/source`,
`/api/version`, the About page, and `php artisan orbital:about`. The
repo itself has NOT been made public; that stays a deliberate act.

- [x] **Secret sweep.** Swept `.env.example`, `docker-compose.yml`,
      `compose/*.yml`, `helm/`, `config/`, `database/seeders/`, and
      `app/` for literal credentials and private keys. **Nothing real
      found.** What's there and why it's fine:
      - `DB_PASSWORD=password`, `PGADMIN_DEFAULT_PASSWORD: 'password'`,
        `ICECAST_*_PASSWORD=changeme` — dev defaults so `sail up` works
        out of the box. `orbital:status` already flags unrotated
        defaults; `SECURITY.md` says rotate before taking real calls.
      - `helm/orbital/values.yaml` `apiKey: "APIorbitaldev"` — the
        public half of the LiveKit key pair, documented as such inline.
        The secret half only ever lives in a Kubernetes Secret.
      - `docker/asterisk/config/version.txt` — a config-generation
        fingerprint UUID, not a credential.
      - `.env` is gitignored.
- [ ] **Decide on the CLA mechanism.** `CLA.md` exists and
      `CONTRIBUTING.md` asks for a comment-based signature. If contribution
      volume ever justifies it, wire up a CLA bot instead of tracking
      signatures by hand.
- [ ] **Publish a security contact.** `SECURITY.md` points at
      `security@calltheory.com` and a PGP key at
      `/.well-known/security.txt`. Both need to exist before the repo is
      public, or the disclosure path is a dead link.
- [ ] **Set `ORBITAL_SOURCE_URL` per environment.** It defaults to the
      upstream repository. Any deployment running modified code must
      point it at its own source — that's the substance of the section 13
      obligation, and the About page says so to whoever reads it.

## Critical — before production

- [x] **Observability stack — metrics + dashboards out of the box.**
      All four phases shipped.
      - **Phase A — done.** Scrape configs for Patroni, etcd, Postgres
        (exporter), Valkey (multi-target exporter), SeaweedFS, HAProxy,
        LiveKit, Loki, Grafana. `logger.conf` now sends the Asterisk
        stream to stdout so Promtail ships it with everything else.
        **Infrastructure** dashboard provisioned.
      - **Phase B — done.** `GET /metrics` per app instance (request
        rate + duration histogram + build info), recorded by
        `RecordHttpMetrics` into Valkey. Hand-rolled `Exposition`
        renderer instead of `spatie/laravel-prometheus`: every metric
        is read from a shared store or pushed by a single writer, so
        the cross-FPM-worker aggregation that library exists to solve
        isn't a problem we have. **Orbital Application** dashboard.
      - **Phase C — done.** `orbital:collect-metrics` (scheduled every
        minute, `onOneServer()`) polls AMI via `AsteriskAmiService`,
        reads the realtime `queue_log` table for per-queue
        offered/answered/abandoned/wait, and pushes to a new
        `pushgateway` container. **Telephony** dashboard.
      - **Phase D — done.** **LiveKit & AI** dashboard: AI-vs-human
        split for calls and messages, chat sessions, knowledge base
        size, LiveKit node health. LiveKit's own internal metric names
        vary by build, so that dashboard carries a discovery table
        panel listing every `livekit_*` series rather than hard-coding
        names that break on upgrade.
      - Deployment-wide numbers are **pushed** rather than exposed per
        replica — see the reasoning in `config/metrics.php`. Getting
        this backwards would silently multiply every total by the
        replica count.

- [x] **Error reporting — optional, Sentry-compatible.** Unhandled
      exceptions to GlitchTip, Sentry, or anything speaking the same
      ingest API. `config/observability.php` +
      `App\Providers\ObservabilityServiceProvider` +
      Settings → Platform → Error Reporting.
      - **Off unless switched on AND given a DSN.** A toggle on its own
        is treated as off, deliberately: the alternative builds a client
        that discards everything while the operator believes errors are
        being captured, which is worse than being off. The provider also
        forces `sentry.dsn` to null when disabled, so a stray
        `SENTRY_LARAVEL_DSN` inherited from a base image can't switch on
        off-site reporting behind the operator's back. Tested.
      - **`App\Services\Observability\Scrubber` gates every event.**
        This platform handles other people's callers and an exception
        report is a copy of whatever the process was holding. Caller
        names, numbers, message bodies, DTMF, recording URLs and
        credentials are redacted by key-substring match (`phone`,
        `caller_phone`, `from_phone`, `phone_number` all hit one rule);
        `send_default_pii` is forced off rather than exposed as a
        setting; user identity is rebuilt as id + team id only.
      - Config mapping runs from a `booting()` callback registered
        **after** `RuntimeConfigOverrideProvider`, so the admin-UI value
        wins over `.env`. Get that order wrong and the failure is
        invisible: works from `.env`, silently ignores the UI.
      - Sentry's own performance tracing is pinned to 0. Traces belong
        to the OTLP pipeline; two half-populated trace backends is worse
        than one.
      - Remaining: no self-hosted GlitchTip compose overlay yet, so
        today this points at an instance you already run. Not on the
        About page either — an operator can't currently confirm it's
        live without causing an error.

- [x] **Tracing — OpenTelemetry to Tempo (or any OTLP backend).** Off by
      default, endpoint-agnostic, `App\Services\Observability\Tracer` +
      `TraceRequest` middleware + Settings → Platform → Tracing.
      - **Manual instrumentation at five seams** — HTTP kernel, database,
        queue jobs, outbound HTTP, console commands — not the
        `opentelemetry` PECL extension. The extension isn't in the Ondrej
        PPA, so it would mean a `pecl` build in `docker/8.4/Dockerfile`
        (Ubuntu + PPA) *and* a differently-shaped one in the production
        `Dockerfile` (`php:8.4-fpm-alpine`), in an image whose header
        names Trivy hit-count as a design constraint. Adding it later is
        additive and invalidates none of this.
      - **OTLP over HTTP on 4318, never gRPC.** gRPC would need
        `ext-grpc` in the image and a `mode tcp` HAProxy frontend instead
        of the ordinary HTTP ones the rest of the obs tier uses.
      - **Parent-based sampling at 5%.** Sampling each hop independently
        would give a two-service trace a 1-in-400 chance of surviving
        intact, and the rest arrive with holes — which read as work that
        never happened. Tested in both directions: an upstream `-01`
        forces a trace in at ratio 1e-7, an upstream `-00` keeps it out
        at ratio 1.0.
      - **Root-span ownership** (`owner:` on `startRoot`/`endRoot`). Found
        while writing this: a job on the `sync` driver is processed
        *inside* the request that dispatched it, so the queue seam's
        JobProcessed would have closed the **request's** span and
        orphaned every span after the dispatch. Nested `Artisan::call()`
        and job-inside-job take a depth counter for the same reason.
      - **Bounds and blast radius.** `TRACING_MAX_DB_SPANS` (100) caps
        database spans per trace, because an accidental N+1 would breach
        the backend's per-trace span limit and get the *whole* trace
        rejected — a performance bug destroying the evidence of itself.
        A failed exporter stands the pipeline down for the process rather
        than retrying a dead connection on every span: a monitoring
        problem must not become an outage. Both tested.
      - Spans carry identifiers only. The database seam records the SQL
        and deliberately not the bindings — the statement is the shape of
        the work, the bindings are the caller's phone number. Tested.
      - Infrastructure: `compose/obs-tracing.yml` (opt-in overlay),
        `docker/tempo/tempo.yaml` on the same SeaweedFS S3 pattern as
        Loki, `tempo-2` in `compose/ha-obs.yml`, `tempo_otlp_fe` /
        `tempo_query_fe` in HAProxy, Grafana Tempo datasource with Loki
        `derivedFields` and `tracesToLogsV2` correlation, a `tempo`
        scrape job, a health card, Helm `observability.*` values +
        secrets, `docs/admin/observability.md`.
      - The Python agent worker gets `agent-worker/observability.py` and
        injects `traceparent` into its Orbital API calls, so a call
        crosses the process boundary as one trace. Env-only there — the
        worker has no path for fetching infrastructure config from the
        API, which `docs/ENVIRONMENT.md` now says explicitly.
      - **Fixed while in here:** the `orbital-app` Prometheus job targeted
        `laravel:80` / `laravel-2:80`, but the compose services are
        `orbital.test` / `orbital.test-2` and nothing declared that alias.
        That scrape has been down under compose since it was written —
        app dashboard blank, app perfectly healthy. Added `aliases:` on
        the `sail` network so one scrape config works under both compose
        and Helm.
      - Remaining: log lines don't carry the trace id yet, so the Tempo
        datasource has `filterByTraceID: false` (turn it on once they do,
        or the panel returns empty and looks broken); no self-hosted
        GlitchTip overlay; neither integration is shown on the About page,
        so an operator can't confirm either is live without causing an
        error or a traced request.

- [x] **Alerting — Alertmanager, SMTP + webhook.** Dashboards make
      problems visible to someone looking; this makes them visible to
      someone who isn't. 14 rules in
      `docker/prometheus/rules/orbital.yml`, all against metrics that
      already existed — nothing needed new instrumentation.
      - **Alertmanager runs by default and delivers nothing until given
        a destination.** `ALERT_EMAIL_TO` is the switch: set it and the
        email receiver is rendered, leave it blank and no email block is
        written at all (Alertmanager refuses to start on an empty `to:`).
        Unconfigured, alerts still group, dedupe, and show in its own UI.
      - **SMTP is the recommended default because it leaves the box.** An
        alert route that runs through the application being monitored is
        not an alert route — when Laravel or Valkey or Postgres is down,
        that is exactly when the notification still has to get out. This
        is also why the in-app Filament notification surface was NOT
        used as the primary receiver, despite already being wired.
      - **`docker/alertmanager/` is a built image**, not `prom/alertmanager`.
        Alertmanager has no environment-variable expansion of its own —
        no `-config.expand-env` equivalent — so the smarthost, sender,
        recipient and password would otherwise have to be committed to a
        tracked file. Renders via `envsubst` at start, the same pattern
        as haproxy / valkey-ha / seaweed-ha, and validates with
        `amtool check-config` before exec so a mistake surfaces at boot
        rather than as alerts quietly never arriving. All three render
        paths (unconfigured / SMTP no-auth / SMTP with auth) were
        verified to produce valid YAML.
      - **`OrbitalMetricsPipelineStale` is the alert about the alerts.**
        Platform metrics are PUSHED to Pushgateway, which serves the last
        value forever — so a dead scheduler freezes every series at its
        last healthy reading and every rule below goes quietly green.
        Silence is indistinguishable from health, which is the worst
        failure mode alerting has. Watches `push_time_seconds` and
        inhibits every `source: pushed` alert while firing.
      - `OrbitalCallsInProgressStuck` alerts on `min_over_time`, not on
        the current value: real concurrency falls back to baseline
        between bursts, so a floor that never drops is lost hangup
        events, not a busy day.
      - Inhibition rules mean a critical alert suppresses warnings with
        the same `alertname`/`team`, so an Asterisk outage reads as one
        problem rather than eight.
      - Pair (`alertmanager` + `alertmanager-2`) gossiping on 9094.
        Prometheus is configured with BOTH nodes directly rather than
        through HAProxy — the gossip is what prevents duplicate
        notifications, and an LB would hide a dead node while silently
        halving the redundancy. HAProxy fronts the UI only.
      - Health card, Prometheus self-scrape of Alertmanager (a silent
        Alertmanager and a healthy system look identical from the
        inbox), `.env.example` block, `docs/admin/observability.md`.
      - **Webhook caveat, documented rather than worked around:**
        Alertmanager's webhook body is a fixed JSON schema and cannot be
        templated, so ntfy and similar receive raw JSON. For phone push,
        pointing `ALERT_SMTP_SMARTHOST` at ntfy's own SMTP listener
        reuses the receiver already configured and gives a formatted
        message, instead of running a translating bridge.
      - Remaining: no Slack/PagerDuty receivers (webhook covers them);
        rules not yet exercised against a live Prometheus — `promtool`
        and Docker are both unavailable in this environment, so they
        were validated structurally and every metric name cross-checked
        against the collectors, but not evaluated.

- [ ] **2FA — Phase 2 (WebAuthn) and Phase 3 (email OTP) still open.**
      Phase 1 (TOTP enrolment) and **Phase 4 (enforcement) are done**.

      **Phase 4 — shipped.** `RequireTwoFactor` middleware on all three
      panels, `App\Services\Auth\TwoFactorPolicy` holding the decision,
      `teams.two_factor_grace_days` (0–30, default 7) with a platform
      fallback for staff, and the countdown banner via `BODY_START` that
      turns red in the last 48 hours. Required for everyone — staff and
      client users alike.
      - The grace clock starts on a user's **first request under the
        policy** (`users.two_factor_grace_started_at`), not at account
        creation. Counting from `created_at` would have locked out every
        existing user the moment this shipped. There's a test for it.
      - The middleware exempts the security page, logout, non-GET, and
        Livewire XHR, or a blocked user has no way to comply — the
        security page is itself a Livewire component.
      - `TWO_FACTOR_REQUIRED=false` switches it off without a deploy,
        because turning it on is the kind of change that can lock people
        out.

      **Phase 2 (~2d) — WebAuthn / passkeys** via `laragear/webauthn`.
      First-class passkey login *plus* second-factor hardware keys from
      one credentials table. Not attempted here: the registration and
      assertion ceremonies are browser↔server and can't be meaningfully
      verified without a real browser and an authenticator. A half-wired
      auth path is worse than none, so this wants a session where it can
      actually be exercised end to end.

      **Phase 3 (~1d) — email OTP fallback.** Weakest of the three (an
      attacker with the mailbox has the account) but needed for users who
      won't adopt an authenticator. Also not attempted here: Fortify's
      two-factor challenge assumes a TOTP secret exists, so a user with
      *only* email OTP never triggers the challenge at all. It needs
      custom login-pipeline work, not just a new challenge action, and
      that's worth doing deliberately rather than alongside four other
      workstreams.

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

- [ ] **Messaging channel follow-ons.** The SMS/MMS channel is live end
      to end: carrier webhook with per-provider signature verification,
      routing, threading, operator inbox with inline reply and
      per-message delivery state, and an AI auto-reply path (off by
      default). Twilio is the one shipped driver.

      **Shipped since:**
      - **Sender pools are mandatory.** An endpoint is identified by its
        carrier sender pool (`messaging_endpoints.sender_pool_id`) —
        Twilio's Messaging Service and its equivalents — not by a bare
        number, and `TwilioProvider::send()` refuses outright rather than
        falling back to a naked `From`. That fallback was the compliance
        hole: a message from a bare number bypasses Twilio's opt-out
        enforcement entirely, so a customer who texted STOP kept
        receiving messages. Inbound now routes on `MessagingServiceSid`
        first, which also means a number the client adds to their pool
        routes correctly without anyone mirroring the change here.
        `address` is nullable and, where a pool is in use, a display
        label only.
      - **STOP / START / HELP.** `messaging_opt_outs` per client (not per
        number — the FCC's 2024 revocation rules read broadly, and so
        does the customer). `OptOutRegistry` is the one place that
        answers "may we text this person"; `OutboundMessageService`
        consults it before every send, the AI job stands down before
        spending a token, and the operator UI closes the reply box with
        a banner. Keyword matching is whole-message only — "stop by at
        four" is an appointment. Admin surface at Clients → Do Not Text;
        opting somebody back in is one-at-a-time, confirmed, and
        recorded against the operator.
      - **MMS media into SeaweedFS.** `FetchMessageMediaJob` pulls
        attachments off the carrier through the driver (Twilio's media
        URLs need the account's basic auth) and into the object store,
        then rewrites `message_entries.media` with the storage path.
        Signed short-lived links in the UI; non-image/video/audio types
        stored as `application/octet-stream` so an SVG attachment can't
        run as a script in our origin; an SSRF host allowlist on the
        fetch. `MESSAGING_MEDIA_DELETE_FROM_PROVIDER` deletes the
        carrier's copy afterwards, off by default because it's
        irreversible.

      **Remaining:**
      - **More transports.** Telnyx and Bandwidth are the obvious next
        two; SMPP and WCTP/paging matter for the answering-service
        market specifically. All four are a `MessagingProvider`
        implementation plus a line in `config/messaging.php` — the
        routing pipeline doesn't change. Note the contract grew four
        methods (`requiresSenderPool`, `senderPoolLabel`, `fetchMedia`,
        `deleteMedia`), so a new driver has more to fill in than before.
      - **Outbound-initiated conversations.** Everything today starts
        with an inbound message. Letting an operator text a customer
        first needs a compose surface and a think about consent — and
        with the suppression list now in place, that check is already
        written.
      - **AI-side intake capture.** Operators can now write a
        conversation up as a message (below), but
        `ProcessMessageWithAgentJob` still only replies — it never
        extracts fields or produces a `Message`. `MessageThread.fields`
        exists and is unwritten. Until that lands the partial policy has
        nothing to govern on the AI path, and an SMS intake the customer
        abandons produces nothing at all.

      **Shipped since:**
      - **`orbital:backfill-message-media`.** Re-queues
        `FetchMessageMediaJob` for entries whose media still points only
        at the carrier — anything from before the fetch job existed,
        plus anything whose fetch failed (the job leaves the provider
        URL in place rather than losing the attachment). `--dry-run`,
        `--since`, `--limit`. Not scheduled: it's a migration aid, and
        on a timer it would re-walk the whole table forever to find
        nothing. The media filter runs in PHP, not SQL — the column is
        `json`, not `jsonb`, and Postgres gives `json` no equality
        operator to test against.
      - **Taking a message from a text conversation.** "Take message" on
        the thread view, writing a real `Message` into the client's
        portal with `messages.message_thread_id` recording where it came
        from. Complete-versus-partial is decided by
        `PartialMessagePolicy` — the same service the voice path uses,
        because an answering service can't sensibly keep half-finished
        messages from a call and discard them from a text — not by which
        button the operator pressed. The callback number is prefilled
        from the address they texted from, which is the one thing this
        channel always knows.
        - No intake goal to consult here: message queues carry an
          orchestration, not a goal, so the client-level default
          applies. Same explicit seam as `SessionMessageWriter::goalFor()`.

## First-run gaps found while writing the VKE runbook

- [ ] **A fresh Helm install has no login.** `FirstSuperAdminSeeder`
      only acts when `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` are
      set, and the chart never sets them (correctly — a password passed
      through Helm values is readable via `helm get values` by anyone
      with cluster read). So `helm install` succeeds and nobody can log
      in until someone runs `orbital:make-admin`.
      `docs/getting-started/vultr-vke.md` documents that step and
      `.env.example` now documents the vars, but the chart's `NOTES.txt`
      should say it too — that is where a first-time installer actually
      looks.
- [ ] **`orbital:make-admin` prompts interactively** with no flags,
      which hangs under `kubectl exec` without `-it`. Worth a clearer
      failure, or a `--no-interaction` path that errors instead of
      blocking.

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

### Operator activity tracking

- [ ] **Operator history events.** Track every meaningful operator
      action as a timestamped event — fetching/closing accounts,
      dialing numbers, answering/ending calls, claiming/unclaiming
      threads, changing availability status, logging in/out. Gives
      supervisors a timeline of what each operator did and when,
      useful for QA review, training, and accountability. Could
      reuse the `ConversationActivity` polymorphic pattern or build
      a dedicated `operator_events` table. Should be lightweight
      (fire-and-forget inserts, no blocking) and queryable by
      operator + date range. Admin/supervisor UI to browse the
      timeline per operator.

### Other

- [ ] **Template tenants for common answering-service patterns.**
      Ship a set of "start here" tenants we (and future platform
      operators) can clone for new clients. Each one exercises a
      different mix of AI + human + routing primitives we already
      have, and surfaces gaps that need new features. Templates:
      - **Voicemail-only** — no AI, no operator, just take a message,
        email it to the tenant. Tests bare intake-flow → message path.
      - **Live operator only** — no AI, humans take every call.
      - **Live operator with AI overflow** — humans pick up when
        available; AI handles when everyone's busy or after hours.
      - **Virtual assistant** — directory of named people at the
        tenant. Call comes in, operator (AI or human) greets, asks
        who the caller wants, transfers to the right person (or
        takes a message if they're unavailable). This one likely
        needs new primitives: a tenant-scoped directory model and a
        `transfer-to-contact` agent action. Also needs to prove the
        SAME workflow works whether an AI or a human is fronting
        the call.
      - **(Existing)** — whatever we have today gets codified as
        a fifth template so new tenants have a known-good baseline.
      Work splits into: (1) inventory what's already composable from
      RoutingRule + CallQueue + IntakeFlow + AgentPersona, (2) fill
      the gaps, (3) seeders that spin up each template + docs.
- [ ] **Phone book / directory in the softphone widget.** When an
      operator is working inside a tenant context, the phone widget
      should expose that tenant's directory (contacts, on-call
      staff, shared lists) so transfers and outbound calls don't
      require switching pages. Needed for the virtual-assistant
      template above to be usable by human operators.
- [x] **Partial-message policy when caller hangs up mid-intake.**
      Shipped. `teams.keep_partial_messages` (client default, off) with
      a three-state `intake_goals.keep_partial_messages` override
      (null = inherit — never give that column a default, or every new
      goal silently opts out of a client-wide policy). Messages carry
      `is_partial` + `partial_reason` + `missing_fields`.
      `App\Services\Messages\PartialMessagePolicy` holds the decision;
      `SessionMessageWriter` applies it on the AI side and the operator
      Workspace gets a "Save Partial" button on the same rules.
      - The AI side needed a session END signal to work at all —
        `POST /api/call-sessions/{key}/end` plus the LiveKit
        `room_finished` webhook as a backstop for a worker that dies.
        Without it, an incomplete capture is indistinguishable from a
        conversation still in progress.
      - Threshold beyond the toggle: a callback number, or a name plus
        something they said. "Caller rang and said nothing" is an
        abandoned call, which the call log already records.
      - Still open: the worker doesn't report its active intake goal, so
        the per-goal override can't fire on the AI path yet. The seam is
        `SessionMessageWriter::goalFor()` — one method, when the worker
        starts sending it.
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
- [ ] **Pluggable infrastructure backends — internal ↔ external
      swap.** Let the platform operator toggle whether the stack
      uses our shipped container or an external endpoint they
      supply, per service. Canonical case: **MinIO ↔ real S3** —
      admin flips a setting, enters endpoint + keys + bucket,
      we provision the buckets with the same policy the MinIO
      bootstrapper applies, flip the `s3` disk config, and the
      app keeps running. Flipping back should restart the MinIO
      container and re-bootstrap it. Same shape works for: SMTP
      (Mailpit ↔ any relay), Redis (Valkey ↔ external), Ollama
      (↔ external inference), Prometheus / Loki / Grafana (↔
      external observability stack), Reverb (↔ Pusher / Ably /
      another self-hosted). Doesn't work for: Asterisk (deep ARA
      + generated-config coupling), LiveKit SIP bridge (the SIP
      side is infra you still own even if you point at LiveKit
      Cloud for media).
      **Implementation note.** Don't build a generic framework
      up-front — start with the first real customer ask
      (probably MinIO ↔ S3), build exactly that swap carefully,
      then extract a pattern once there are 2–3 real shapes to
      compare. Per-service `ServiceProvider` abstraction +
      settings UI + bootstrapper-per-backend comes later.
      **HA / failover is the related axis** — most of these
      targets already support HA natively (distributed MinIO,
      Postgres streaming replication, Valkey Sentinel, LiveKit
      horizontal via Redis coordination), and the "Big items"
      section below covers the active-active plan. The pluggable
      backend work should land first so HA can build on top of
      "point at external" as one of its primitives.
- [ ] **MinIO Console OIDC login UI.** The embedded Console in
      MinIO `RELEASE.2025-09-07` reports `loginStrategy: form` on
      its `/api/v1/login` endpoint even with a fully configured
      named OIDC provider (role policy attached, display name set,
      auto-discovered metadata reachable). `mc idp openid list`
      shows the provider enabled and `mc` itself can authenticate
      via OIDC tokens, but the browser UI never surfaces the SSO
      button. Likely fixes to try: bump to a newer MinIO release,
      or drop the standalone `minio/console` sidecar (which has
      confirmed OIDC UI support) in front of the object server.
      For now the Console login page uses the root credentials as
      a fallback — programmatic S3/mc access via OIDC still works.
- [ ] **Portal-side metrics embedding.** The provisioning path is
      done — four dashboards ship in
      `docker/grafana/provisioning/dashboards/orbital/` and load on
      container start. What's left:
      - **Admin-side embedding** (super-admins only): iframe
        Grafana's `/d-solo/<uid>/<slug>?panelId=X` URLs into
        Filament widgets where useful. Auth is a non-issue
        because the viewer is already super-admin and can
        legitimately see everything.
      - **Tenant-facing embedding** (customer portal):
        **don't** iframe Grafana — build a small
        `PrometheusQueryService` in Laravel that wraps
        `GET /api/v1/query_range` and render the results with
        Chart.js / ApexCharts in a Filament widget. Why: tenant
        scope filtering happens naturally via the `client_id`
        label already carried by every per-client platform metric
        (`orbital_calls_recent`, `orbital_messages_taken_recent`,
        `orbital_emails_received_recent`, …), the visual style matches the rest
        of the portal, and there's no second authz layer to
        reimplement in Grafana territory. ~100 lines for the
        query service + 1 widget per chart.

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

**Shipped:** `orbital:backup` / `orbital:restore` — encrypted nightly
PostgreSQL backup to an off-box S3-compatible destination, scheduled at
03:00 when `BACKUP_ENABLED=true`. See `docs/admin/backups.md`.

- **Encryption is libsodium secretstream (XChaCha20-Poly1305)**,
  streamed a MiB at a time so a large dump never sits in memory, with
  the passphrase stretched via Argon2id against a per-archive salt.
  Chosen because it is AUTHENTICATED: a wrong passphrase, a corrupted
  download, and a truncated upload are all refused before anything
  touches a database, and the explicit end-of-stream tag means a
  cut-short archive is detectably incomplete rather than decrypting
  cleanly up to the cut. That is also why there is no separate checksum
  file — a checksum an attacker can rewrite was never protection.
  All four failure modes have tests.
- **Destination credentials are separate from the app's** (`BACKUP_AWS_*`,
  own disk). Backups under the key the app writes recordings with die to
  the same compromised credential or buggy delete that destroys the
  recordings, which is the one failure a backup exists to survive.
- **Order of operations is the design:** dump, encrypt, upload, THEN
  prune. Retention runs only after the new archive is stored, so a run
  of failing backups can never age out the last good one. Names that
  cannot be parsed as ours are never deleted.
- **Silent failure is the real risk**, so `orbital_backup_enabled`,
  `orbital_backup_last_success_timestamp_seconds` and
  `orbital_backup_last_bytes` are exposed, with three alert rules:
  `OrbitalBackupStale` (48h), `OrbitalBackupNeverSucceeded`, and
  `OrbitalBackupShrankSharply` (under half the fortnight high-water
  mark — a dump that failed partway and uploaded anyway). "Never backed
  up" reports an explicit zero rather than a missing series, so a
  dashboard can tell it apart from a broken metrics pipeline.
- **The APP_KEY trap is recorded in the manifest.** Encrypted columns
  (platform settings, 2FA secrets, tokens) use APP_KEY, not the backup
  passphrase, so restoring into an install with a different key leaves
  them as noise while everything else looks perfect. Each archive stores
  a fingerprint so the mismatch is visible before it becomes a mystery.
- `postgresql-client` added to BOTH images for pg_dump/pg_restore — a
  deliberate addition to an image that otherwise keeps its package list
  minimal, because there is no substitute and a platform holding other
  people's call records without a restore path is not shippable.

**Remaining:**
- **Object storage content is NOT backed up** — recording audio, MMS
  media, mail attachments. Documented rather than hidden: the database
  is the part that cannot be reconstructed, and bucket content is better
  served by the storage layer's own versioning + delete protection.
  Turn those on at the provider.
- No admin UI (destinations, schedule, history table, "Run now"), no
  SFTP or multi-destination support, no per-tenant config exports, and
  no `backup_runs` history table — last-success state currently lives in
  two `platform_settings` rows.
- The pg_dump/pg_restore path itself has no automated test: the suite
  runs on SQLite. Everything either side of it is covered; the dump
  itself is exercised by hand against a real database.

