# Orbital — Feature Catalog for the Marketing Site

Source material for the Orbital marketing site. Organized by theme
with a headline, short elevator pitch, concrete capability bullets,
and (where relevant) why it matters to the buyer.

The buyer is a **platform operator** — a call-center company that
wants to run Orbital to take calls on behalf of their customers.
Their customers are **clients** — businesses that want an answering
service but don't want to run the infrastructure themselves.

Two customer journeys need to show up on the site:

- **Platform operator journey**: "I want to stand up an answering
  service and resell it to my own clients."
- **End-customer journey (client)**: "I want a professional answering
  service for my business, with a modern portal and AI-enabled
  routing options."

---

## 1. Positioning — The platform-operator model

**Headline**: Run your own answering service. Own the stack. Own the margin.

**Pitch**: Orbital is installable software that turns your call-center
operation into a multi-tenant SaaS. Each of your clients becomes a
client with their own portal, their own message history, their own
DIDs, their own AI personas. You keep the operator seats, the
infrastructure, and the billing relationship. Nothing routes through
a vendor cloud — the whole stack runs on your hardware or your cloud.

**Key differentiators**:
- Self-hosted, no SaaS middleman; data never leaves your stack
- Multi-client from the ground up; one install serves all your clients
- Both AI agents and human operators handle calls side-by-side
- Open telephony (Asterisk + Kamailio + LiveKit), not a proprietary PBX
- Deterministic billing — you meter usage against clients with no
  per-minute fee paid to anyone upstream

---

## 2. Answering service — the core

**Headline**: Every call answered. Every message delivered.

**Capability bullets**:
- Inbound SIP trunks from any carrier (Twilio, Bandwidth, your own)
- Per-client DIDs with pattern-based routing rules
- Time-of-day conditions (business hours, after-hours, weekends,
  holidays)
- Queue-based overflow between AI agents and human operators
- Call recording to client-isolated storage with retention controls
- Message intake flows defined per-client, per-script
- Email-to-message for callers who hit voicemail
- Inbound email handling alongside calls — same workspace

---

## 3. AI voice agents

**Headline**: Conversational AI that sounds human, follows your script,
and knows when to hand off.

**Pitch**: Build agent personas once; deploy them per-client with
client-specific knowledge, voice, and personality. Powered by LiveKit
for low-latency audio, Anthropic for reasoning, ElevenLabs for voice.
Every agent is bounded by a script so it doesn't go off-rails.

**Capability bullets**:
- Persona-based configuration: name, voice, personality, backing LLM
- Per-client knowledge bases for grounded answers
- Unified scripts: the same script drives AI *and* human operators so
  behavior is consistent whichever path a call takes
- Structured intake goals — collect name, number, reason for call,
  urgency, custom fields
- Real-time barge-in; caller can interrupt the agent
- Automatic escalation to a human operator on ambiguity, caller
  request, or script condition
- Recording + transcription available for every AI call

**Why it matters**: A small call-center can cover 10× the volume with
the same human headcount. AI takes the repetitive intake calls; humans
handle the judgment calls.

---

## 4. Live operators

**Headline**: A modern workspace for the people answering the phones.

**Pitch**: Browser-based softphone with one-click call acceptance,
in-browser WebRTC audio, integrated message intake, and tight
integration with the AI agents running alongside. No desk phone
required.

**Capability bullets**:
- WebRTC softphone in the browser — no desktop app
- Call queue with skill-based routing
- One-screen workspace: caller ID, client context, script, intake
  form, and history
- Automatic call recording
- Operator availability states with mandatory reason on sign-out
  (audit-grade attendance logging)
- Drain-aware: planned maintenance migrates operators to the surviving
  node without dropping active calls
- Email inbox in the same workspace — one operator can handle voice
  and email queues from one browser tab

---

## 5. Multi-tenancy

**Headline**: One install. Every client isolated.

**Capability bullets**:
- Every domain model is tenant-scoped via the `BelongsToTeam` trait
- Client portal: customers see their own call history, messages,
  recordings, agents, stats — nothing else
- Platform staff can impersonate into any client for support
- Per-client: branding in the portal, AI persona config, knowledge
  base, scripts, intake goals, DIDs, trunks, routing rules, queue
  config, operator assignments
- Clients can grant portal access to their own users with
  role-scoped permissions

---

## 6. Platform administration

**Headline**: One pane of glass for everything you run.

**Pitch**: Filament-based admin panel with deep per-client control,
live telephony state, and a Failover Central page for infrastructure
operations.

**Capability bullets**:
- Three distinct panels: Admin (platform staff), Operator (the people
  answering calls), Portal (client users)
- Client CRUD with cloning + template provisioning
- AI persona CRUD with live voice preview
- Script editor with versioning
- Intake goal + flow editor
- Routing rule editor with drag-to-reorder priority
- Queue configuration with skills + overflow targets
- DID / SIP trunk management
- User + role management with Spatie permissions
- Audit logs for every destructive operation (`failover_audit_logs`,
  `user_logout_events`, and more)

---

## 7. High availability, business continuity & disaster recovery

**Headline**: Installed once. Ready for production.

**Pitch**: Orbital ships with a full active/active HA stack, not a
"nice to have" you bolt on later. Every customer-facing service has
at least two instances. Plan a maintenance window? Drain a node from
the admin UI, wait for zero active calls, restart, activate. No
third-party dependency in the failover path.

**HA architecture bullets**:
- **Data**: 3-node PostgreSQL cluster (Patroni + etcd quorum,
  sync_standby for RPO=0 failover), 3-node Valkey + 3-node Sentinel
  for cache/queue HA, distributed SeaweedFS object store (3 masters,
  N volumes, N filers)
- **App**: 2× Laravel, 2× Reverb websocket broadcast, 2× agent worker,
  2× Haraka SMTP, 2× Ollama
- **Telephony**: 2× Kamailio behind VRRP SIP VIP, N× Asterisk with
  shared realtime config, 2× LiveKit + 2× LiveKit SIP bridge, 2×
  Icecast
- **Observability**: 2× Grafana on shared Postgres, 2× Prometheus
  scrape pair, 2× Loki on SeaweedFS-backed object storage
- **Internal LB**: 2× HAProxy behind VRRP internal VIP
- **Edge**: 2× nginx-tls behind VRRP public VIP

**Admin UX bullets**:
- **Failover Central page** with per-tier colored borders
  (green/yellow/red at-a-glance), role tags (leader / sync standby /
  replica / master), and one-click controls for Patroni switchover
  and Valkey failover
- **SIP Proxy page** for per-node Asterisk drain with live active-call
  + registration counts and a "Safe to reboot" indicator
- **Dynamic Asterisk registry** — add or remove Asterisk nodes from
  the dispatcher pool through the admin UI; Kamailio reloads itself
  without a config edit
- **Operator drain notifications** — when an Asterisk node is drained
  for maintenance, operators pinned to that node get a real-time
  toast telling them to finish their call and refresh
- **Audit log** of every failover action with actor, target, and raw
  control-plane output

**DR story (in roadmap)**:
- Continuous WAL archiving via pgBackRest to a dedicated repo host
- Full nightly backups with 7-day retention (tunable)
- SeaweedFS replication=001 keeps a second blob copy on every write
- Cross-site replication + promotion is a future phase; single-site
  HA ships today

**Why it matters**: Every call-center marketing site claims "99.9% uptime."
Very few show you the actual failover controls. Orbital does.

---

## 8. Telephony

**Headline**: Open telephony, first-class citizen.

**Capability bullets**:
- Asterisk 22 LTS with realtime ARA (all config in Postgres, no file
  edits needed to add an endpoint)
- Kamailio SIP proxy with dispatcher-based load balancing, dialog
  tracking, and JSON-RPC admin interface
- LiveKit for WebRTC + AI voice agents
- Softphone via SipJS, WebRTC over WSS through HAProxy
- Call recording to S3-compatible storage (SeaweedFS by default;
  swap to AWS S3, Cloudflare R2, or MinIO via a config flag)
- Music on hold via Icecast relay pair
- Inbound email → Haraka → Laravel pipeline (SendGrid Inbound Parse
  model, no mailbox required)

---

## 9. Security

**Capability bullets**:
- TLS-terminating nginx edge with ACME (Let's Encrypt) automation
- SIP TLS (5061) + secure WebRTC (WSS 8089)
- TOTP two-factor authentication (shipped); WebAuthn / passkeys +
  email OTP in the pipeline
- Role-based access control (Spatie) across three panels
- Destructive actions require typed confirmation matching the target
  name
- Every significant admin or operator action is auditable
- Optional OAuth2 SSO for pgAdmin, SeaweedFS console, and other
  infra UIs (platform-scoped)
- No outbound public-internet calls at runtime — fully offline-capable
  install possible

---

## 10. Offline-first / self-hosted advantages

**Headline**: No SaaS dependency. Air-gap friendly.

**Capability bullets**:
- Every JS / CSS asset bundled locally via Vite; zero CDN references
- Ollama-backed local embeddings + inference as a fallback when a
  hosted LLM is unreachable
- MinIO / SeaweedFS for local S3-compatible storage
- Can run entirely on your own infrastructure — on-prem, private
  cloud, or public cloud, your choice
- No per-call or per-minute fees to a vendor

---

## 11. Observability (roadmap, partially shipped)

Shipped:
- Grafana, Prometheus, Loki running in the HA stack
- Promtail shipping container logs to Loki
- System Status bar across every admin panel showing live component
  reachability

Coming:
- Infrastructure dashboard (LiveKit, Postgres, Valkey, SeaweedFS
  metrics)
- Orbital App dashboard (request rate, queue depth, per-client call
  counts)
- Telephony dashboard (active channels, dispatcher state, AMI
  polling)
- AI / LiveKit dashboard (active rooms, agent worker jobs, LLM
  latency)

---

## 12. Billing & metering (roadmap)

Not shipped yet; should be in the "coming soon" list for the
marketing site.

Planned:
- Per-client call-minute counters (AI vs human)
- Per-client message volume (inbound + outbound email)
- Per-client recording storage usage
- Exportable billing CSV for month-end invoicing
- Optional Stripe integration for direct invoicing of clients

---

## 13. Template clients (roadmap)

Planned starter configurations that ship with every install:

- **Voicemail-only** — no AI, no operator, take a message and email it
- **Live operator only** — humans take every call, no AI
- **Live operator with AI overflow** — humans first, AI picks up when
  everyone's busy or after hours
- **Virtual assistant** — directory of named people; call gets
  greeted, routed, transferred, or sent to voicemail based on script
- **Call center queue** — classic skill-based routing to operator pools

Every template seeds working routing rules, queues, scripts, and
(where relevant) an AI persona, so a new client is usable in minutes
without manual configuration.

---

## 14. Extensibility / developer story

Capability bullets:
- Laravel 12 + Filament 5 + Livewire 4 — modern, well-documented
  framework
- Python LiveKit agent worker for custom agent behavior
- Blade-generated Asterisk + Kamailio configs from the database —
  no hand-editing
- Every API surface usable from outside the admin UI via Sanctum
  tokens
- Webhooks for call events and message intake (roadmap)
- Horizon-managed queues; Reverb for real-time events
- Well-factored service layer: `KamailioService`, `AsteriskDrainService`,
  `HAProxyStatsClient`, etc.

---

## Screenshot shot list (for the marketing site)

Orbital has several screens that would carry a marketing page by
themselves. Recommended captures, with the URL path under `/admin/`:

| Shot | Path | Caption idea |
|------|------|--------------|
| **Failover Central** at full health | `/admin/failover` | "Every tier. One page. At a glance." |
| **Failover Central** with a deliberately downed node | `/admin/failover` (kill a patroni replica first) | "See exactly what's degraded, in real time." |
| **SIP Proxy** mid-drain with counters | `/admin/sip-proxy` (drain a backend) | "Drain a node in one click. Wait for zero. Reboot." |
| **Asterisk Backends** list | `/admin/asterisk-backends` | "Add an Asterisk node through the UI — dispatcher rewires itself." |
| **System Status bar** | top of any admin page | "Every component. Every page. Never surprised." |
| **Operator workspace** with active call + intake form | `/operator` during a test call | "One screen. Every answer in reach." |
| **Softphone widget** with availability pill | `/operator` | "Browser-native calling. No desk phone. No installer." |
| **Client portal** inbox | `/portal/messages` as a client user | "Your clients get a portal, not a phone number." |
| **AI persona editor** | `/admin/resources/agent-personas/{id}/edit` | "Configure an AI once. Reuse it per client." |
| **Intake flow editor** | `/admin/resources/intake-flows/{id}/edit` | "Same script. AI follows it. Human follows it." |
| **Call recording player** | a CallLog detail page | "Every call. Recorded. Playable. Searchable." |
| **Dark mode** | any admin page with dark toggled | "Works how your team works." |

To capture these: run `composer dev`, log into `orbital.test/admin`
as a super-admin, and navigate to each URL. A good capture tool is
`chrome --headless --screenshot` or Playwright for full-page shots.

If the marketing-site Claude wants to script these, the admin URLs
are deterministic once a demo dataset is seeded via
`php artisan db:seed`.

---

## Tone + voice notes for the marketing site

- **Concrete over abstract**: show the Failover Central screenshot
  instead of saying "enterprise-grade HA."
- **Name the software**: "Postgres, Patroni, SeaweedFS, LiveKit" —
  the buyer is technical-adjacent; specificity builds credibility.
- **Own the positioning**: "self-hosted," "no SaaS middleman,"
  "open telephony." This is our differentiator vs Answer1 / PatLive /
  Smith.ai.
- **Two audiences, one site**: lead with the platform operator
  story on the home page; have a sub-page for the end-customer
  (client) story that talks about the portal experience.
- **Don't oversell AI**: AI voice agents are a capability, not the
  whole product. The HA + multi-tenant story is the moat.
