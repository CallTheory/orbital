# Blog Post Outline — Orbital: What Works Today & How It Stays Up

> Draft outline. Two parts: (1) a feature tour split into working / nearly-there /
> roadmap, and (2) a plain-language summary of the failover architecture.
> Maturity labels are drawn from the code, `TODO.md`, and `docs/admin/high-availability.md`
> as of July 2026 — sanity-check before publishing.

---

## Part 1 — Working title ideas

- "One call surface for AI and humans" (product angle)
- "Building an answering service that doesn't go down" (reliability angle)
- Recommend leading with the product story, then paying off with the reliability story.

## Part 2 — The hook (intro, ~2 short paragraphs)

- Orbital is a multi-tenant answering service: a call center runs it to take calls
  and messages *on behalf of* its own customers.
- The differentiated idea: **AI voice agents and human operators share one
  call-handling surface** — same scripts (flows), same recordings, same history —
  so a call can hand off mid-conversation. That's the thread to pull through the post.

---

## Part 3 — What's working today (the differentiated core)

Lead here — these are shippable and, in the case of telephony, well-tested.

- **Telephony core** — SIP trunks, extensions, DID/time-based routing rules, call
  queues with AI overflow, multi-surface call recording. Configs are generated from
  the database, not hand-edited. *(Most mature area; the only one with a real test suite.)*
- **AI voice agent** — Python LiveKit worker. Per-persona pluggable providers
  (Anthropic default, plus OpenAI/OpenRouter/local Ollama for LLM; ElevenLabs/OpenAI
  TTS; ElevenLabs/Deepgram STT). Barge-in, warm SIP transfer, per-participant recording.
  *(Working core; note some agent tools are logged no-ops in v1 — see caveats.)*
- **The shared-flow operator handoff** — operators get a checklist view of the *same*
  compiled flow the AI runs, so a human can pick up where the agent left off. This is
  the money feature; give it its own screenshot/example.
- **Voicemail + transcription** — record → webhook → provider-pluggable transcription
  → object storage → email the client. End-to-end.
- **Knowledge base / RAG** — per-tenant pgvector store; the AI agent's `search_knowledge`
  is the one tool with real upstream behavior in v1.
- **Tenant portal** — read-only call history, messages, and recordings for the
  call center's customers.
- **Platform admin & auth** — clients, users, roles, personas, voices, team-scoped
  multi-tenancy; well-tested auth/teams.

## Part 4 — Nearly there (worth previewing)

- **Visual flow-graph editor ("Orchestrations")** — the flagship in-flight feature.
  A Svelte Flow canvas for authoring the call scripts that feed AI, operator, *and*
  chat surfaces, backed by a real compiler + expression engine (with unit tests).
  Platform-shared orchestrations rebind per-tenant. Great demo material; be honest
  that per-port edges and mid-call field inheritance are still landing.
- **Inbound email / threading** — SMTP → webhook → route → operator inbox, with
  RFC822 threading and AI auto-reply. Phases 1–4 shipped; the AI round-trip is
  blocked only on an API key, not code. Backlog: rich-text composer, attachment
  previews, full-text search.
- **Chat channel** — public web chat widget that reuses the orchestration compiler.
  Functional but thin.
- **Messaging (SMS/MMS)** — carrier webhook → signature verification → routing →
  threading → operator inbox, with an inline reply box and per-message delivery
  state. Threads reuse the email channel's claim/release semantics and the same
  orchestration compiler, so a client's flow drives voice, email, and text from
  one definition. Twilio driver ships; the provider contract is the seam for
  everything else. AI auto-reply exists but is off by default.

## Part 5 — On the roadmap (set expectations honestly)

- **RCS / SMPP / WCTP / paging** — the provider contract and queue model cover
  them, but only Twilio (SMS/MMS) has a shipped driver. (SMS/MMS itself has
  moved up to Part 4.)
- **Contacts / directory** — partial; softphone phone-book and tenant contact portal pending.
- **Cross-region DR** — single-site HA works today; multi-region is a later phase.

## Part 6 — Honesty callouts (a short "what we're still building" box)

Readers trust a roadmap that admits gaps. Pull 3–4:
- Some AI agent tools are acknowledged no-ops in v1 (field write-through is partial).
- 2FA is TOTP-only today (WebAuthn/passkeys, enforcement middleware pending).
- Messaging ships one carrier driver (Twilio); RCS/SMPP/WCTP are contract-only.
- Metrics and dashboards ship, but there's no alerting yet — the graphs tell you
  something is wrong once you're looking at them.

Also worth a paragraph of its own now: **Orbital is AGPL-3.0**. Self-hosting is
free forever with no feature gates, no seat counting, and no license key; what
Call Theory sells is hosting, support, and services. That's a credibility
argument the rest of the post can lean on — "you can read the code that takes
your customers' calls" is a stronger claim than any feature list. See
`LICENSING.md`.

---

## Part 7 — Reliability summary (the failover story)

Full operator handbook lives in [`docs/admin/high-availability.md`](../admin/high-availability.md);
this is the condensed version for the post.

### The one-sentence version

Orbital runs **single-site active/active**: every customer-facing service has at
least two instances, so losing any single node keeps the service up. The tradeoff
we're upfront about — **a call already in progress on the lost node drops; only new
calls are protected.** Cross-region DR is a separate future phase.

### The tier map (nice as a graphic)

```
EDGE          2× nginx-tls (VRRP public VIP)
TELEPHONY     2× Kamailio (VRRP SIP VIP) · N× Asterisk · 2× LiveKit · 2× LiveKit SIP · 2× Icecast
APP           2× Laravel · 2× Reverb · 2× agent-worker · 2× Haraka · 2× Ollama
OBSERVABILITY 2× Grafana · 2× Prometheus · 2× Loki · Promtail per host
INTERNAL LB   2× HAProxy (VRRP internal VIP)
DATA          3× Postgres (Patroni + etcd) · 1× Barman · 3× Valkey + 3× Sentinel · 3+2+2 SeaweedFS
```

Deployed via a base `docker-compose.yml` plus five HA overlays under `compose/`
(`ha-data`, `ha-app`, `ha-telephony`, `ha-edge`, `ha-obs`), each mapped to a rollout phase.

### How each layer stays up

- **SIP edge (signaling) — Kamailio ×2.** The SIP front door and dispatcher-based
  load balancer. Active/active in dev, active/standby behind keepalived VRRP in prod.
  The Asterisk backend pool is generated from the database; adding/removing a node
  reloads the dispatcher on *both* Kamailios with no restart. Drain state fans out to
  every node so it survives a VRRP failover.
- **PBX — Asterisk ×N (dynamic registry).** All nodes share endpoint/dialplan state
  via realtime Postgres, so they're interchangeable. Because active calls are pinned
  to the node that took them, this is the one tier with real per-node *drain* controls
  (drain → wait for zero calls → reboot), driven from the SIP Proxy admin page.
- **Media — rtpengine ×2 (active/active, at the edge).** Relays RTP and owns recording
  (pcap at the edge). Call state lives in the shared Valkey cluster so an in-flight
  call can survive a node loss; per-node spool is mounted into Laravel for upload.
- **App tier — all stateless, ×2.** Laravel, Reverb (websockets; shared Valkey pub/sub
  with sticky sessions), agent-worker, Haraka (inbound mail), Ollama. Restart freely;
  a load balancer or DNS round-robin fronts each.
- **Data tier — the replicated core.**
  - *Postgres ×3* — Patroni + a 3-node etcd for leader election. One leader (writes),
    one sync-standby (RPO=0 failover target, ~10–15s auto-promote), one async replica
    for reporting. Barman handles continuous WAL archiving + backups, mirrored to S3.
  - *Valkey ×3 + Sentinel ×3* — Sentinel promotes a replica on primary loss (~5s);
    clients reconnect through Sentinel.
  - *SeaweedFS (3 masters + 2 volumes + 2 filers)* — object storage for recordings,
    logs, and WAL; replication gives one extra copy per write. Most self-healing tier.
- **Internal load balancing — HAProxy ×2.** Fronts Postgres (read/write split), Valkey
  (always routes to current master), and SeaweedFS S3. Stateless; both run at once, VRRP
  VIP in prod. Write actions (drain/enable server) fan out to every node.
- **AI agents — LiveKit ×2 (separated from Asterisk on purpose).** Asterisk owns SIP/PBX
  and operator legs; LiveKit owns AI-voice/WebRTC rooms. They fail independently — an
  Asterisk failover doesn't touch AI rooms and vice-versa. (Caveat: existing LiveKit
  rooms are bound to their instance, so a restart drops rooms on that node.)

### Failover Central — the operator console

One super-admin dashboard (`/admin/failover`) polls every tier and shows green/yellow/red
health, understanding the *expected* shape of each pool (e.g. the Postgres write pool is
"correct" when exactly one node is up — the leader). Incident actions: **Patroni
switchover** and **Valkey force-failover** (both with typed confirmation), plus per-node
rtpengine drain. **Every destructive action is written to an audit log** with actor,
target, and the raw control-plane output. Two companion pages handle Asterisk drain
(SIP Proxy) and node registration (Asterisk Backends).

### What we're honest about (reliability gaps)

- No "pause auto-failover" toggle in the UI (shell out to `patronictl pause`).
- No LiveKit drain — a restart drops in-flight AI rooms on that instance.
- No in-app VRRP/keepalived control (infrastructure-layer).
- Single-site only — no cross-region DR yet.

---

## Part 8 — Close

- Restate the through-line: one shared surface for AI + humans, built on a stack that's
  designed so a single node loss is a non-event.
- CTA: link to calltheory.com/orbital.

## Notes for the writer

- Screenshots worth grabbing: the flow-graph editor, the shared-flow operator checklist,
  Failover Central's tier grid.
- Keep the honesty box — it's more credible than an all-green feature list.
- Don't over-promise messaging or dashboards; they're clearly roadmap.
