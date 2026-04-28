# Plan: rtpengine at the Kamailio edge

**Status:** proposed
**Branch:** `worktree-rtpengine-edge`
**Owner:** Patrick

## Why

Three things are tangled together and one change unlocks all of them:

1. **Audio terminates at the edge.** Today RTP flows directly between
   endpoints and Asterisk (10000-10099) and between LiveKit-SIP and the
   world (50000-60000). Two media planes, both bound to the inside of
   the platform. Putting `rtpengine` on the Kamailio edge VMs gives one
   coherent media surface, hides internal topology from carriers, and
   lets us force-relay every dialog so we always see the bytes.

2. **It's the K8s migration enabler.** Asterisk's 100-port UDP range
   and LiveKit-SIP's 10 000-port range are the reason the platform is
   still on docker-compose. Once rtpengine owns RTP at the edge, those
   services only expose SIP signaling and become trivially K8s-able.
   Asterisk + LiveKit + LiveKit-SIP + agent-worker + Laravel/Horizon/
   Reverb all move into K3s/K8s; only the data-plane stays on bare
   metal/VMs where UDP performance matters.

3. **Per-leg recording becomes free and better than MixMonitor.**
   For each SIP dialog, rtpengine sees one offer/answer pair = two
   directional RTP streams. With `output-mixed = no` and
   `output-single = yes`, recording-daemon writes two WAV files per
   leg: caller's mic only, and everything the caller heard. Direction-
   of-flow is the diarization. No VAD, no speaker embeddings — feed
   each WAV into transcription and merge by timestamp.

The "rtpengine for media + K8s for the rest" architecture is the
load-bearing change. We do it in three phases so each ships
independently and the rollback story is clean.

## Recording surfaces — two, not three

Every SIP dialog in the platform — external caller, operator-to-
operator, outbound AI dial-out, anything — terminates at the edge
through rtpengine. The only calls that don't traverse SIP at all
are pure LiveKit-room scenarios (AI ↔ AI, operator joining a LK
room directly). LiveKit Egress catches those. MixMonitor is
retired entirely.

| Scenario                                                  | Surface                       |
|-----------------------------------------------------------|-------------------------------|
| External caller ↔ AI (LiveKit room)                       | rtpengine + LiveKit Egress    |
| External caller ↔ Operator (WebRTC)                       | rtpengine                     |
| External caller ↔ Operator ↔ AI handoff                   | rtpengine + LiveKit Egress    |
| Operator ↔ Operator (SIP extension to extension)          | rtpengine                     |
| Outbound AI dial-out                                      | rtpengine + LiveKit Egress    |
| Operator ↔ AI inside LK room (no SIP)                     | LiveKit Egress                |
| AI ↔ AI inside LK room (no SIP)                           | LiveKit Egress                |

**Surface 1: rtpengine recording-daemon (edge).** Every SIP dialog
on the platform. Per-leg WAVs (caller-in, caller-out) by direction
of flow. Achieved by moving operator WebRTC registration from
Asterisk's WSS listener to Kamailio's WSS listener — see
"Operator WebRTC routes through Kamailio" below.

**Surface 2: LiveKit TrackEgress (per-participant).** Every call
that involves a LiveKit room. TrackEgress (not RoomCompositeEgress)
writes one audio file per participant — each AI agent gets its own
track WAV, each human-in-room gets their own. Same per-speaker
diarization story as rtpengine. agent-worker triggers Egress at
room-create; output lands in SeaweedFS / S3.

`CallRecordingService` resolves which surface(s) apply per call.
The `call_recordings` table gets a `source` enum
(`rtpengine_edge` | `livekit_egress`) so multi-surface recordings
of the same call group by `call_uuid`.

## End state

```
                                      (edge VMs, 2× active/active)
External SIP ─────► Kamailio + rtpengine ◄──── DMQ peering between nodes
                          │     │
                          │     └─── Valkey (cluster, via HAProxy) for call state
                          │
                          ▼  (RTP relay, force-mode)
                    ┌─────────────────────────────────────┐
                    │  Kubernetes cluster                 │
                    │                                     │
                    │  Asterisk pods (5060 only)          │
                    │  LiveKit pods                       │
                    │  LiveKit-SIP pods (5069 only)       │
                    │  agent-worker pods                  │
                    │  Laravel / Horizon / Reverb         │
                    └─────────────────────────────────────┘
                          │
                          ▼
                    rtpengine recording-daemon
                          │
                          ▼
                    SeaweedFS / S3 (per-leg WAV pairs)
```

Recording always happens at the edge regardless of where the call
ultimately lands (Asterisk operator, LiveKit AI agent, external
transferee). Same recording surface, same code path, same legal model.

---

## Phase 1 — rtpengine inline on the two Kamailio VMs

Co-locate rtpengine + recording-daemon on each existing Kamailio VM.
Keep keepalived/VRRP warm-standby. Asterisk and LiveKit-SIP keep
their wide RTP ranges *temporarily* — they'll get stripped in Phase 2
once we trust rtpengine in production.

This phase ships independently. If it goes sideways we revert one
Kamailio config change and we're back to today's topology.

### Files added

- `docker/rtpengine/Dockerfile` — builds rtpengine 11.x with
  recording-daemon, opus, libavcodec
- `docker/rtpengine/rtpengine.conf.tmpl` — Blade-rendered, parameters
  for interface, port range, redis (no-op in P1), recording dir
- `docker/rtpengine/recording-daemon.conf.tmpl` — output spool,
  output-format=wav, output-mixed=no, output-single=yes, metadata-pattern
- `docker/rtpengine/entrypoint.sh` — render config, load `xt_RTPENGINE`
  kernel module if available (falls back to userspace forwarding if not)
- `compose/ha-rtpengine.yml` — overlay defining `rtpengine-1` and
  `rtpengine-2` services pinned to the Kamailio VMs

### Files changed

- `docker/kamailio/kamailio.cfg`
  - `loadmodule "rtpengine.so"`
  - `modparam("rtpengine", "rtpengine_sock", "udp:127.0.0.1:22222")`
  - In `route[RELAY]`: `rtpengine_manage("force trust-address replace-origin replace-session-connection ICE=remove");`
  - In `onreply_route`: matching `rtpengine_manage()`
  - On `BYE`: `rtpengine_delete()`
  - Set per-call `X-Orbital-Tenant-Id`, `X-Orbital-Call-Uuid`,
    `X-Orbital-Record` headers from `dispatcher` attrs / DB lookup
- `app/Services/Telephony/KamailioService.php`
  - Extend `setBackendState()` fan-out to also reach each rtpengine
    NG control socket for graceful drain (no new calls relayed by
    a draining rtpengine, in-flight calls finish)
  - New `RtpengineService` that wraps NG protocol (offer/answer/
    delete/list/start-recording/stop-recording)
- `app/Services/Telephony/CallRecordingService.php`
  - Resolution stays the same (per-extension → per-team → platform)
  - Output is now a struct passed via SIP `X-Orbital-Record-*` headers:
    `enabled`, `metadata` (call_uuid, team_id, leg_role), `format`
  - Kamailio reads these headers and translates into rtpengine NG
    `record-call=yes` + `metadata` flag
- `app/Jobs/UploadCallRecordingJob.php`
  - Trigger source flips from Asterisk AMI `Hangup` to a watcher on
    the rtpengine recording-daemon spool (inotify or post-finalize hook)
  - Reads paired WAVs (`*-recv.wav` and `*-send.wav`), uploads as a
    `CallRecording` with `direction` = `caller_in` / `caller_out`
  - For multi-leg sessions, group by Asterisk `linkedid` already in
    `CallLog`; the model gets a `recording_pair_count` column
- `database/migrations/{date}_create_call_recordings_per_leg.php`
  - Add `direction` enum column to `call_recordings`
  - Add `leg_uuid` column for stitching multi-leg sessions
- `docker/asterisk/config/` (dialplan templates)
  - **Remove `MixMonitor()` entirely.** rtpengine owns recording
    for every SIP dialog now, including operator-to-operator (see
    next section).
- `docker/asterisk/config/pjsip-webrtc.conf`
  - **Remove the WSS listener.** Asterisk only takes SIP from
    Kamailio over UDP/TCP after this change. Operators no longer
    connect to Asterisk directly.
- `docker-compose.yml` + `compose/ha-telephony.yml`
  - Add rtpengine services pinned to the kamailio VMs
  - rtpengine ports: 22222/udp NG control (loopback only),
    public RTP range exposed on host (e.g. 30000-40000/udp)

### Operator WebRTC routes through Kamailio

Today operator softphones (SipJS in `app/Livewire/`) register
directly to Asterisk's WSS listener on 8089. That bypasses
Kamailio and therefore bypasses rtpengine, leaving operator-to-
operator calls invisible to the edge. To bring them under the
single recording surface, move the WSS listener from Asterisk
to Kamailio.

- `docker/kamailio/kamailio.cfg`
  - `loadmodule "websocket.so"`, `loadmodule "tls.so"`
  - WSS listener bound to public interface, e.g. port 7443
  - Routes operator INVITEs to Asterisk via the existing
    dispatcher (no new logic — operators look like any other
    SIP UAC to Kamailio)
- `docker/kamailio/tls.cfg`
  - Cert provisioning (re-use the existing nginx-tls cert chain
    or issue a dedicated one for the SIP edge hostname)
- `app/Livewire/` softphone component(s) — change WSS URL from
  `wss://asterisk-host:8089/ws` to `wss://sip-edge.host:7443/`
- `app/Models/Extension.php` / softphone provisioning
  - Update the registration metadata sent to operators
- `docker/asterisk/config/pjsip.conf`
  - Drop the WSS transport (Asterisk only takes SIP from Kamailio
    on UDP/TCP after this)

After this change operator-A → operator-B SIP-extension calls
flow operator-A WSS → Kamailio + rtpengine → Asterisk → Kamailio
+ rtpengine → operator-B WSS, and rtpengine writes per-leg WAVs
exactly like external calls.

**Costs accepted:** Kamailio gains TLS termination duty (small
ops overhead, well-trodden pattern). Edge VMs carry internal call
audio in addition to external (estimated +5-10% bandwidth, not a
concern). Asterisk loses its public WSS listener — actually a
benefit for Phase 2 K8s migration, since Asterisk pods no longer
need any externally-reachable port.

### Wire Kamailio into the existing TLS cert pipeline

Cert issuance + renewal already exists: acme.sh writes PEMs to a
shared `tls-certs` Docker volume, then a deploy-hook POSTs to
`TlsRenewalWebhookController` which dispatches
`ReloadServicesAfterCertRenewalJob`. The job already has a TODO
naming Kamailio as a future consumer. The TLS admin page
(`app/Filament/Pages/TlsCertificates.php`) already lists Kamailio
in `CertificateService::getConsumingServices()` but flagged "not
yet consuming." We finish that wiring as part of this phase.

- `compose/ha-telephony.yml` (and Helm equivalent in Phase 2)
  - Mount the shared `tls-certs` volume read-only into both
    Kamailio containers at `/etc/tls/`
  - Both rtpengine + Kamailio share the volume; rtpengine doesn't
    need TLS itself (RTP/SRTP keying is per-call), but the volume
    is already there
- `docker/kamailio/tls.cfg`
  - Point at `/etc/tls/fullchain.pem` and `/etc/tls/privkey.pem`
    (same paths every other consumer uses)
  - TLS profile for the WSS listener: TLSv1.2+, modern cipher list
- `docker/kamailio/kamailio.cfg`
  - WSS listener on 7443 references the tls profile above
- `app/Services/Telephony/KamailioService.php`
  - New method `reloadTls()` calling Kamailio's `tls.reload`
    JSON-RPC method on each node. **Must fan out to every Kamailio
    node** (per the existing HAProxy/Kamailio fan-out pattern in
    `setBackendState()`); reloading only the first reachable node
    leaves the second serving the old cert until it restarts.
- `app/Jobs/ReloadServicesAfterCertRenewalJob.php`
  - Replace the existing TODO comment with a real Kamailio reload
    block. Same try/catch wrapping pattern already used for
    Asterisk PJSIP — one service's failure doesn't block the
    others. Logs go to the existing failover/audit trail.
- `app/Services/CertificateService.php`
  - Update `getConsumingServices()` entry for Kamailio: drop the
    "not yet consuming" caveat, list both consumed ports
    (`5061/tcp` SIP TLS + `7443/tcp` WSS for operator softphones),
    and call out that **operator softphones will fail to register
    if the cert SAN doesn't cover the SIP-edge hostname.**
- `app/Filament/Pages/TlsCertificates.php`
  - The page reads `getConsumingServices()` so the UI updates
    automatically. Add a SAN-coverage warning row: if the cert's
    `sanList` doesn't include the configured SIP-edge hostname,
    surface a yellow banner with the missing hostname and a link
    to "Issue Certificate" prefilled with the expanded SAN list.
- `config/tls.php`
  - Add `sip_edge_hostname` config key (defaults to `sip.{domain}`).
    `CertificateService` uses this both to validate SAN coverage
    and to pass to acme.sh `--issue --domain` when re-issuing.

**Verification additions:**
- After cert renewal, `tls.reload` JSON-RPC succeeds on **both**
  Kamailio nodes and `openssl s_client -connect sip-edge:7443`
  shows the new cert chain on each node's public IP.
- TLS admin page reports Kamailio as healthy/consuming and shows
  the WSS port alongside the existing SIP TLS port.
- SAN warning banner appears if the SIP-edge hostname is missing
  from the cert; clicking through to Issue Certificate
  pre-populates with the corrected SAN list.

### LiveKit Egress integration (Surface 2)

Recording any call that involves a LiveKit room — including pure
AI ↔ AI and operator ↔ AI flows that never traverse the SIP edge.

- `agent-worker/` — on `room_created` (AI session start), call
  LiveKit Egress API to start `TrackEgress` per participant
  (one audio file per AI agent track + one per any human
  participant track). Output target = SeaweedFS S3-compatible
  endpoint. Metadata includes `team_id`, `call_uuid`, `room_name`.
- `app/Services/Telephony/CallRecordingService.php`
  - Resolution returns `surfaces[]` not a single decision. For an
    external-caller-to-AI call, surfaces = `[rtpengine_edge,
    livekit_egress]`. For AI ↔ AI training, surfaces = `[livekit_egress]`.
- `app/Jobs/IngestLivekitEgressJob.php` (new)
  - Webhook handler for LiveKit Egress completion events
  - Reads track files from SeaweedFS, registers `CallRecording` rows
    with `source=livekit_egress`, `direction=participant_track`,
    `participant_identity=...` for grouping
- `database/migrations/{date}_add_recording_source_to_call_recordings.php`
  - `source` enum: `rtpengine_edge` | `livekit_egress`
  - `participant_identity` nullable string (LK only)
- `app/Models/CallRecording.php`
  - Scopes per source; UI groups recordings by `call_uuid` and shows
    each source as a separate row with its tracks listed.
- `docker/livekit/livekit.yaml` / values
  - Enable Egress service (`egress` block); for self-hosted this is
    the LiveKit Egress sidecar / deployment
  - Configure S3 output to point at SeaweedFS endpoint with
    appropriate bucket / credentials

### Outbound-trunk-via-Kamailio invariant (load-bearing guardrail)

If any outbound INVITE ever reaches a carrier without going through
Kamailio + rtpengine, that call is silently un-recorded. We make
this impossible by construction, not by convention:

- `docker/asterisk/config/pjsip-trunks.conf` — **remove direct
  endpoints to carriers.** The only outbound endpoints are the
  Kamailio dispatcher set. Asterisk has no path to a carrier IP.
- `docker/kamailio/kamailio.cfg` — outbound route requires the
  INVITE to have come from a known internal source (Asterisk pod
  or LiveKit-SIP pod) AND must call `rtpengine_manage(...)` before
  forwarding upstream.
- `app/Services/Telephony/Realtime/OutboundTrunkAuditor.php` (new)
  - Periodic check: enumerates Asterisk pjsip endpoints, fails
    loudly if any endpoint resolves to a carrier IP block.
- Compose / Helm: Asterisk pods don't have egress NetworkPolicy
  routes to carrier ranges — only to Kamailio's internal SIP
  service IP. Belt-and-suspenders so a misconfig can't reach a
  carrier even by accident.

This invariant is the single most important Phase 1 commitment.
Without it, "every external call is recorded" is a hope, not a
guarantee.

### LiveKit Cloud placeholder (cheap to add now)

- Add nullable `livekit_target` enum column to `teams`:
  `'self_hosted' | 'cloud'`, defaults `'self_hosted'`
- `BindingResolver` / dispatch logic reads the column and picks
  dispatcher set in Kamailio routing
- No actual cloud routing wired up — just the column and the
  per-tenant resolver hook so it's not retrofitted later

### Verification

- Place test call through the edge: caller → kamailio → rtpengine →
  asterisk → AI agent. Confirm two WAVs land on edge spool with
  correct metadata.
- `rtpengine-ctl list` shows the call active during the dialog.
- `wireshark` on edge VM confirms no RTP between caller IP and
  Asterisk IP — only caller↔edge and edge↔asterisk.
- Failover test: kill `rtpengine-1`, VRRP moves VIP to node 2,
  new calls succeed; in-flight calls drop (acceptable for warm-standby).
- **Recording-surface coverage matrix.** Run one call per row of
  the surface table above and confirm each lands the expected
  files in `call_recordings`:
  - external ↔ AI: rtpengine pair + LK Egress per-participant tracks
  - external ↔ operator: rtpengine pair only
  - operator ↔ operator (SIP extension to extension): rtpengine pair
  - operator ↔ AI inside LK room (no SIP): LK Egress only
  - AI ↔ AI: LK Egress only
- **Operator-WSS-via-Kamailio invariant.** Confirm Asterisk's
  WSS listener is removed and operator softphones register only
  through Kamailio's WSS endpoint. Attempting to register directly
  to Asterisk must fail.
- **Outbound-trunk invariant.** Attempt to originate a call from
  Asterisk directly to a carrier IP (bypassing Kamailio). Must fail.
  `OutboundTrunkAuditor` must report zero direct-carrier endpoints.

### Open issues to flag before merging Phase 1

- **DTMF.** Verify RFC 2833 passes through cleanly. In-band DTMF
  is rare in the existing trunk mix but call out if any provider
  uses it. SIP INFO is signaling, no rtpengine involvement.
- **Codec mix.** rtpengine relays without transcoding by default.
  If any tenant negotiates G.729 ↔ Opus and we want recording in
  a single codec, enable transcoding (`transcode=PCMU` etc) at
  the cost of CPU on the edge VM.
- **T.38 fax.** If anyone uses fax (unlikely on a virtual-receptionist
  platform), rtpengine will need T.38 gateway mode. Otherwise
  ignore and document as "fax not supported."

---

## Phase 2 — Strip RTP from Asterisk and LiveKit-SIP, move app stack to K8s

Once Phase 1 is steady-state, Asterisk and LiveKit-SIP no longer need
their wide UDP port ranges. The only thing keeping them on
docker-compose was that constraint.

### Files removed / changed

- `docker-compose.yml`, `compose/ha-telephony.yml`
  - Asterisk: drop `10000-10099:10000-10099/udp` port mapping
  - LiveKit-SIP: drop `50000-60000:50000-60000/udp` port mapping
  - Both keep their SIP signaling ports
- `docker/asterisk/config/rtp.conf`
  - Restrict to bind on cluster-internal interface only
  - Port range can shrink (cluster-internal RTP doesn't need 100 ports)
- `docker/livekit/sip-config.yaml`
  - Same — internal RTP only

### Files added (K8s)

- `k8s/` (new directory) or `helm/orbital/` if going the chart route:
  - `Chart.yaml` + `values.yaml`
  - `templates/laravel-deployment.yaml`
  - `templates/horizon-deployment.yaml`
  - `templates/reverb-deployment.yaml`
  - `templates/asterisk-statefulset.yaml` (StatefulSet because
    Asterisk has node identity in dispatcher routing)
  - `templates/livekit-deployment.yaml`
  - `templates/livekit-sip-deployment.yaml`
  - `templates/agent-worker-deployment.yaml`
  - `templates/services.yaml`
  - `templates/configmap-asterisk.yaml`
  - `templates/secrets.example.yaml`
- `docs/admin/k8s-deployment.md` — operator guide for cluster setup,
  node labels, RBAC, the network reachability requirement back to
  the edge VMs
- `.github/workflows/build-images.yml` — image build & push for the
  components moving to K8s

### Out of scope for Phase 2 (decide separately)

- Postgres / Patroni: stays where it is, or migrates to managed
  Postgres. Decide independently of this plan.
- Valkey: stays on the existing 3-node cluster (used in Phase 3).
- SeaweedFS: stays, or pivots to managed S3. Independent decision.
- Grafana / Prometheus / Loki: independent migration plan; they're
  fine staying on VMs for now.

### Verification

- New cluster smoke: a call placed externally hits the edge VMs,
  rtpengine relays into the cluster network, hits an Asterisk pod
  via SIP, dial-out to a LiveKit-SIP pod, AI answers. Per-leg
  recording lands on edge spool exactly as in Phase 1.
- Pod restart resilience: kill an Asterisk pod mid-call. The call
  drops (Kamailio dispatcher hash routes new calls to surviving
  pod). Same as today's behavior — Phase 2 doesn't improve mid-call
  portability, that's a separate concern.

---

## Phase 3 — Active/active edge via cluster Valkey

Edge VMs stay VMs. We just open a network path from them to the
cluster's Valkey HAProxy frontend so rtpengine and Kamailio can
share state across the pair. This is the smallest phase by lines of
code.

### Files changed

- `docker/rtpengine/rtpengine.conf.tmpl`
  - `redis = valkey-haproxy.internal:6379`
  - `redis-num-threads = 4`
  - `redis-auth-pass = ...` (env-injected)
  - `redis-allowed-subnets = ...` (cluster CIDR)
- `docker/kamailio/kamailio.cfg`
  - Load `dmq.so`, configure DMQ peering between the two edge VMs
  - For shared htables (rate limit counters etc), `loadmodule "db_redis.so"`
    pointing at the same HAProxy frontend
- `compose/ha-rtpengine.yml`
  - Drop keepalived dependency for rtpengine
  - Both nodes flagged active

### Infra changes (not files in this repo)

- Firewall / security group rule: edge VM IPs → HAProxy Valkey
  frontend port 6379, TCP only
- Valkey ACL: new users `rtpengine` and `kamailio` with key-prefix
  scoping (`rtpe:*` and `kam:*` respectively). Document in
  `docs/admin/high-availability.md`
- Trunk-side failover model: switch from VRRP-floating SIP VIP to
  one of:
  - DNS round-robin / SRV with low TTL (simplest, what most carriers
    expect anyway)
  - Anycast at the network layer (cleaner but needs network team
    cooperation)

### Prereqs

- **Same DC / low-latency path edge ↔ HAProxy ↔ Valkey.** rtpengine
  hits redis on every offer/answer/manage. Sub-millisecond is fine,
  5ms+ shows up in call setup. Same VPC: golden. Cross-region: don't.
- **Phase 2 must be in production.** No reason to touch edge HA
  while we're still mid-K8s migration.

### Verification

- Place a call. `rtpe:call:*` keys appear in Valkey on both nodes.
- Kill rtpengine-1. The active call survives — node 2 has the state.
  This is the new behavior Phase 3 unlocks.
- Drop test: kill kamailio-1 mid-dialog. DMQ peer notices, dispatcher
  state intact on kamailio-2; surviving in-flight RTP keeps flowing
  through rtpengine-1 (which is still up if we only killed kamailio).

---

## File impact summary

| Phase | Net new files | Files touched | Lines changed (rough) |
|-------|---------------|---------------|----------------------|
| 1     | ~8            | ~12           | 600-900              |
| 2     | ~12 (helm)    | ~6            | 400-600              |
| 3     | 0             | ~4            | 80-120               |

## Risks and unknowns

- **Recording storage volume.** Today recordings are per Asterisk
  node spool. Post-Phase-1 they're per edge VM spool. Edge VMs
  see ~all calls, so disk usage roughly = sum of previous Asterisk
  spools, possibly more if MixMonitor was being skipped sometimes.
  Size storage accordingly.
- **rtpengine kernel module.** `xt_RTPENGINE` gives 10× perf vs
  userspace. Required for any real call volume. Verify the edge
  VM kernel matches a build target supported by rtpengine 11.x.
- **SRTP.** WebRTC operator softphones use SRTP. rtpengine handles
  SRTP↔RTP gateway mode but the keying needs to be tested end-to-end
  before we cut MixMonitor over.
- **Recording metadata loss.** Today CallLog rows correlate to
  recordings via Asterisk uniqueid. Post-Phase-1 the correlation
  key is the rtpengine call-id (= SIP Call-ID). Need to make sure
  CallLog stores the SIP Call-ID, not just Asterisk uniqueid.
  Quick check: does it already? If not, add it in Phase 1.
- **LiveKit-internal audio is not recorded.** rtpengine only sees
  what crosses SIP. Audio inside a LiveKit room (AI agent's
  internal monologue, multi-participant rooms before bridging out)
  is invisible. For 99% of cases this doesn't matter — the caller
  hears what comes out of LiveKit-SIP, and that's what we record.
  Document this so it's not surprising later.

## Open question for Patrick

- **Codec policy on the edge.** Default is "pass-through, no
  transcode." If any tenant or trunk has weird codec requirements
  we may want a per-tenant transcode rule. Worth deciding now or
  defer to "we'll add it when a tenant complains"?
