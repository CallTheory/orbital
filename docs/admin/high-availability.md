# High Availability & Maintenance

This is the administrative guide for running Orbital's HA stack: what each
tier does, how it self-heals, how to run planned maintenance without a
customer-visible outage, and what to do when something breaks.

> **Looking to install Orbital on real infrastructure?** See the [orbital-setup repo](https://git.calltheory.com/calltheory/orbital-setup) — the OpenTofu + Ansible deployment workflow that produces the topology described here. The Helm chart it installs lives in this repo at `helm/orbital/` and ships to Harbor on every tagged release. This doc is the operator handbook for what already exists.


Orbital's HA is **single-site active/active**: every customer-facing service
has at least two instances. Losing any single node keeps the service up.
Mid-call portability is out of scope — an in-progress call pinned to the
lost node drops; only new calls are protected. Cross-region DR is a
separate future phase.

---

## The tier map at a glance

```
EDGE          2× nginx-tls (VRRP public VIP)
TELEPHONY     2× Kamailio (VRRP SIP VIP) · N× Asterisk · 2× LiveKit · 2× LiveKit SIP · 2× Icecast
APP           2× Laravel · 2× Reverb · 2× agent-worker · 2× Haraka · 2× Ollama
OBSERVABILITY 2× Grafana · 2× Prometheus · 2× Loki · Promtail per host
INTERNAL LB   2× HAProxy (VRRP internal VIP)
DATA          3× Postgres (Patroni + etcd) · 1× Barman · 3× Valkey + 3× Sentinel · 3+2+2 SeaweedFS
```

---

## The three admin surfaces

Orbital exposes three pages for HA control. You'll use them in that order
depending on what you're doing:

| Page                     | Scope                                    | Path                       |
|--------------------------|------------------------------------------|----------------------------|
| **Failover**             | Data + LB tiers, at-a-glance health      | `/admin/failover`          |
| **SIP Proxy**            | Drain / activate Asterisk nodes          | `/admin/sip-proxy`         |
| **Asterisk Backends**    | Add / remove Asterisk nodes from the pool | `/admin/asterisk-backends` |

All three are super-admin-only. Every destructive action writes a row to
`failover_audit_logs` with actor, target, and raw control-plane output.

---

## Failover page — the at-a-glance dashboard

Every tier has a colored border:

- **Green** — all expected state holds
- **Yellow** — degraded but serving traffic (one node down, or a lagging replica)
- **Red** — the tier is offline or unreachable

HAProxy backends each render as their own card because the expected shape
differs per pool. For example, `pgsql_rw_be` is *correct* when exactly one
server is UP (the Patroni leader) and the other two are DOWN — they're
correctly failing the `/primary` health check because they're replicas.
The card's border stays green when that shape is intact.

Per-backend role tags appear next to each server name (leader / sync
standby / replica / master / peer / filer) so you don't have to cross-
reference the Patroni and Sentinel cards to read the HAProxy output.

Two action buttons live in-page:

- **Patroni switchover** — graceful leader handoff, picks candidate by name.
- **Valkey force-failover** — Sentinel picks the best-positioned replica.

Both require typed confirmation of the target name.

---

## Per-tier maintenance

### Postgres (Patroni + etcd)

Three Postgres nodes: leader (writes), sync_standby (RPO=0 failover
target), async replica (reporting / analytical queries). Clients reach the
leader via HAProxy `pgsql_rw_be` (port 5432); replicas via `pgsql_ro_be`
(port 5433). The sync_standby gets picked first on leader loss; the
async node is tagged `nofailover` + `nosync` so it never gets promoted
and doesn't affect write latency.

**Self-healing**: yes, for replicas. Kill a replica, restart it, it
reconnects and catches up from the leader. For the leader, Patroni
auto-promotes the sync_standby on failure (~10–15s).

**Planned maintenance (rolling):**
1. Start with the async replica. Restart the container; Patroni resumes
   streaming on boot.
2. Sync_standby next. Same drill.
3. Finally the leader: Failover → Patroni switchover, pick an already-
   updated replica, confirm the name. Writes pause ~5s during handoff.
   Then restart the old leader; it comes back up as a replica.

**What to do when it's red on the Failover page:**
- *Unreachable*: all three Patroni REST endpoints failed to answer. Check
  `docker logs orbital-patroni-1-1` for the live leader; try `docker ps`
  to see if a container is missing.
- *No leader*: etcd quorum is probably down. Verify `orbital-etcd-{1,2,3}`
  are running. etcd needs 2 of 3 to have a leader.

**What to do when it's yellow:**
- A replica is lagging > 32MB or reporting state != streaming. Not urgent
  if the leader is healthy — replica will catch up on the next WAL
  flush. Investigate if lag keeps climbing.

**Gap**: no "pause auto-failover" toggle in the UI. If you need Patroni
to stop reacting while you do disruptive work, `patronictl pause` via
shell, then `patronictl resume` when done.

### Valkey (3-node replica set + 3 Sentinels)

One primary, two replicas, three Sentinels monitoring them. Laravel
connects via the Sentinel-aware predis driver; Kamailio and SeaweedFS
connect through the HAProxy `valkey_be` frontend which always routes to
the current master.

**Self-healing**: yes. Sentinel promotes a replica on master failure
(~5s). Laravel clients reconnect through Sentinel and resume.

**Planned maintenance (rolling):**
1. Restart a replica first. Sentinel notices; HAProxy drops it from
   rotation (it'd fail the AUTH+ROLE tcp-check anyway). Replica
   re-syncs on boot.
2. Second replica same way.
3. Finally the master: Failover → Valkey force-failover (no target
   selector — Sentinel picks). Cache/queue pauses briefly (~1s). Then
   restart the old master; it boots as a replica.

**What to do when it's red:**
- *Sentinel cluster unreachable*: all three Sentinels failed. Unlikely
  unless a network issue wiped them out together.
- *No master*: Sentinels lost quorum. Check that at least two of three
  `orbital-sentinel-*` containers are running.

**Gap**: no pause toggle.

### SeaweedFS (3 masters + 2 volumes + 2 filers)

Most self-healing tier:

- **Masters (3)**: Raft quorum. Can lose one without impact.
- **Volumes (2)**: replication=001 = one blob copy per write. Losing
  one drops writes to read-only on affected volumes until it rejoins;
  reads stay up from the replica.
- **Filers (2)**: share the Valkey-backed metadata store, either can
  serve any S3 API request. HAProxy `seaweed_s3_be` round-robins.

**Planned maintenance (rolling):**
- **Masters**: restart one, wait ~5s for Raft to re-stabilize, restart
  the next. Never lose two simultaneously.
- **Volumes**: restart one at a time; wait for healthz to return OK
  before touching the next so replication catches up.
- **Filers**: restart whichever; HAProxy round-robins to the survivor
  immediately.

**What to do when it's red:**
- *Master cluster unreachable*: majority of `seaweed-master-*` are down.
  Check `docker ps` and restart the downed ones.
- *No filers reachable*: check container logs. The Valkey filer-store
  config requires a reachable Sentinel quorum — fix Valkey first if
  it's also red.

**Gap**: no per-master or per-volume drain control in the UI. Use
`docker stop <container>` directly; the tier handles the rest.

### HAProxy (2 nodes)

Two identical HAProxy instances on the internal network. In dev, app
containers resolve `haproxy` via docker DNS which returns both IPs;
client libraries retry on failure and land on the survivor. In prod,
keepalived VRRP puts one VIP in front of both for single-address clients.

**Self-healing**: yes. Both run simultaneously; stateless. No replication.

**Planned maintenance (rolling):**
- Restart one at a time. Nothing fancy — every write-path operation
  against HAProxy (drain server, enable server) is already fan-out and
  idempotent, so the surviving node carries the state.

**In production with keepalived**: to upgrade the VRRP master, stop
keepalived on that node. The VIP migrates to the backup in ~1s. Do
your work, restart keepalived, and the VIP migrates back.

**Gap**: no in-app VRRP control. Keepalived is infrastructure-layer.

### Kamailio (2 nodes)

Active/active in dev (no VRRP, both take their own inbound traffic).
Active/standby in prod behind keepalived. Dispatcher state writes
(drain/activate) fan out to both nodes via the KamailioService, so a
drain applied from the admin UI survives a VRRP failover.

**Dispatcher list is generated from the database** — the `Asterisk
Backends` admin page writes `dispatcher.list`, triggers
`dispatcher.reload` on both nodes, and changes take effect without a
restart. See [Asterisk Backends](#asterisk-backends-registry) below.

**Planned maintenance (rolling):**
- In dev (no VRRP): restart one, trunk provider retries, lands on the
  survivor. No operator-visible outage.
- In prod (VRRP): stop keepalived on the VRRP master. VIP moves to
  backup (~1s). Restart the old master, bring keepalived back up.

**Gap**: no in-app VRRP control.

### Asterisk (N nodes, dynamic registry)

Every Asterisk reads the same `ps_endpoints`, `ps_aors`, `ps_contacts`
via ARA realtime in Postgres. Softphones register via HAProxy
`asterisk_wss_be` (WSS 8089). SIP trunks land via Kamailio.

**Per-node drain** is the one place with real drain controls because
active calls are pinned to the node that took them.

**Planned maintenance (SIP Proxy page):**
1. Click **Drain** on the node you want to reboot. This:
   - Tells Kamailio to mark the backend as `dp` (drain + probe-on,
     sticky) so new SIP trunk calls go elsewhere.
   - Tells HAProxy to pull the backend out of `asterisk_wss_be` so new
     WSS connections land on the surviving node.
   - Fires a private-channel broadcast to any operator whose softphone
     is currently pinned to the draining node — they see a toast
     telling them to finish their call and hit Ctrl+R.
2. Watch the "active calls / registrations" counters on the card.
   When both hit zero, "Safe to reboot" appears.
3. Restart the container.
4. Click **Activate**.

### Asterisk backends registry

`/admin/asterisk-backends` is the CRUD surface for adding new Asterisk
nodes to the pool. Each save regenerates `docker/kamailio/dispatcher.list`
(bind-mounted into both kamailios) and fires `dispatcher.reload` on
both nodes. Toast tells you if the reload succeeded on every node.

**Adding a third Asterisk node:**
1. Add the service to `compose/ha-telephony.yml` (or add a new compose
   file) with the same ARA realtime env vars as the existing nodes
   and a distinct `ASTERISK_NODE_NAME`.
2. `docker compose up -d asterisk-3`. Wait for it to register with
   Postgres.
3. In the admin UI, **create an `AsteriskBackend` row** with the
   hostname. The dispatcher reload fires and Kamailio starts probing
   the new node.

**Removing a node:**
1. In `/admin/sip-proxy`, **Drain** the node and wait for zero traffic.
2. In `/admin/asterisk-backends`, delete the row (or set is_active=false).
   Dispatcher reload pulls it from the pool.
3. Stop the container.

**Gotcha**: `dispatcher.reload` silently skips unresolvable hostnames.
Always start the container *before* adding the DB row. If you see a
successful reload but the new hostname doesn't show up in
`dispatcher.list` via RPC, check `docker logs orbital-kamailio-1` for
`pack_dest: could not resolve <host>`.

### LiveKit (2 nodes)

Room state in the shared Valkey. New rooms route to whichever LiveKit
instance answers first. **Existing rooms are bound** to the instance
that created them — restarting LiveKit-1 kills rooms currently on it.

**Planned maintenance**: schedule outside peak hours and accept that
in-progress rooms on the restarted node drop. Call-center use rarely
uses LiveKit rooms beyond the AI-voice path, so this is typically low
impact. There's no "drain LiveKit" control in the UI yet (see Gaps).

### Horizon (queue worker)

Runs in its own container (`horizon` compose service). Stateless at
the process level — all coordination happens through Valkey — so it
scales horizontally with no extra plumbing.

- **Dev**: 1 replica; compose `restart: unless-stopped` handles
  crash recovery.
- **Prod (Kubernetes)**: deploy as a `Deployment` with `replicas: 2+`
  for HA. Replicas discover peers via Valkey; queue work shards
  automatically.
- **Health check**: status-bar Horizon card reads
  `MasterSupervisorRepository::all()` — goes red when no master
  is registered in Valkey.

Restart / graceful terminate: run from INSIDE the horizon container,
not orbital.test:

```bash
docker compose restart horizon
# or
docker exec orbital-horizon-1 php artisan horizon:terminate
```

`sail artisan horizon:terminate` from the app container doesn't work
— it signals a PID in orbital.test, not the horizon container.

### Scheduler

Runs `php artisan schedule:work` in its own container (`scheduler`
compose service). Fires every minute, invokes every task in
`routes/console.php`.

**Horizontal scaling caveat**: a naive multi-replica deploy would
fire every task on every replica — double emails, double pruning,
etc. Two correct patterns:

1. **Single replica** (safest default) — `replicas: 1`, Kubernetes
   respawns on crash.
2. **Multi-replica + `onOneServer()` on every task** — Laravel's
   cache-lock does per-task leader election. `replicas: 2+` is then
   safe.

Our convention: **every scheduled task in `routes/console.php`
carries `->onOneServer()`**, even when it doesn't strictly need to
(the heartbeat is idempotent, but uses the guard anyway). That way
prod is free to pick either pattern without auditing task-by-task.

Health check: status-bar card reads a `scheduler:heartbeat` Valkey
key written every minute by a trivial scheduled task. Stale > 2min =
WARN, > 5min = DOWN.

### LiveKit SIP bridge, Agent workers, Haraka, Ollama

All stateless active/active. Restart freely. HAProxy or DNS round-robin
in front of each. Losing one causes at most a brief connect retry on
the client side.

### Nginx-tls edge pair

Two nginx-tls nodes terminate TLS and route to the Laravel upstreams.
In prod, keepalived VRRP puts the public VIP on the active. In dev,
both are reachable on their own IPs; only nginx-tls-1 binds host ports.

**Planned maintenance**: same keepalived pattern as Kamailio/HAProxy.
Stop keepalived on the VRRP master, VIP moves, restart.

### Reverb (2 nodes)

Websocket broadcast server. Both instances subscribe to the same Valkey
pub/sub so events fan out across both. Nginx upstream uses `ip_hash`
stickiness so a single browser stays on one node. On restart, clients
reconnect to the survivor within a few seconds.

### Observability (Grafana, Prometheus, Loki)

- **Grafana** (2 nodes): dashboards + users + datasources live in the
  clustered Postgres, so both nodes see the same state. HAProxy
  `grafana_be` round-robins.
- **Prometheus** (2 nodes): each scrapes independently and keeps its
  own TSDB. HAProxy queries whichever responds first. Minor query-time
  drift between replicas is acceptable for dashboards.
- **Loki** (2 nodes): chunks + index in the shared SeaweedFS S3 bucket
  `loki-chunks`. Either node can serve queries.
- **Promtail**: one per host, not clustered. Reads the host's docker
  socket + container log dir.

All of these are restart-freely-safe.

---

## Backups & restore

### Barman (Postgres)

A dedicated `barman` container stores WAL archives + full backups.
Two parallel WAL pipelines feed it:

- **Streaming** (primary): `pg_receivewal` against the permanent
  `barman_streaming` replication slot. Patroni declares the slot
  in DCS so it exists on every node and survives failover; libpq's
  `target_session_attrs=read-write` in Barman's conninfo picks the
  current writer automatically.
- **archive_command** (fallback): each Patroni node pushes WAL via
  `barman-wal-archive` over SSH. If the streaming pipeline ever
  drops or falls behind, this catches it up.

Full backups use `pg_basebackup` over libpq (no SSH back-channel
needed). Barman's repo volume lives on its own host so a full
Postgres-tier failure doesn't lose backups.

**Schedule (default)**: WAL archiving continuous, retention 7 days
(`RECOVERY WINDOW OF 7 DAYS`). Phase C wires schedule management to
the admin UI under `System → Backups`.

**Verify cluster health**:
```bash
sail exec barman gosu barman barman check orbital
```
All lines should report OK once the cluster has bootstrapped and at
least one full backup exists.

**Take a backup on demand**:
```bash
sail exec barman gosu barman barman backup orbital
sail exec barman gosu barman barman list-backups orbital
```

**Point-in-time restore** — rare, but worth knowing the pattern:
1. `patronictl pause` to stop auto-failover.
2. Stop Postgres on the target node.
3. On the Barman host, recover into a target dir:
   ```bash
   barman recover orbital latest /tmp/restore \
     --target-time "2026-04-19 08:00:00"
   ```
4. Move the recovered dir into PGDATA on the target node, start
   Postgres in recovery; the `restore_command` in patroni.yml
   (`barman-wal-restore`) replays WAL until the target time.
5. `patronictl resume` once the new timeline is stable.

**Replica clone**: Patroni's `create_replica_methods` lists
`barman_recover` first and `basebackup_chmod` as fallback. The
Barman path uses a remote staging dir on the Barman host — the new
replica SSHes in, asks Barman to recover into staging, then rsyncs
the staged tree back to PGDATA. Until the very first full backup
exists, replicas automatically fall back to streaming
`pg_basebackup` from the leader.

**Cloud destinations**:

- *Primary* (always on, defaults to in-cluster SeaweedFS): every
  WAL Barman receives is mirrored to S3 by a `post_archive_script`
  hook. Set per-deployment via `BARMAN_PRIMARY_S3_*` env vars on the
  barman service (endpoint / bucket / key / secret / region).
- *Offsite* (optional): a second S3 destination for DR. Activate
  by setting `BARMAN_OFFSITE_S3_*`. The same WAL hook script
  pushes a second copy when those vars are non-empty; it stays a
  no-op until then.

Full backups are NOT mirrored automatically — that would double
Postgres load on every backup. The destination of a full backup is
chosen per scheduled run from the admin UI (`System → Backups`).

Implementation note: full cloud backups run from a **Patroni
node**, not from the Barman container. `barman-cloud-backup` reads
`pg_control` directly from the data directory, so it has to run
where the data dir is. Each Patroni image ships
`/etc/patroni/cloud-backup-runner.sh primary|offsite` (in the
patroni entrypoint) which expects the AWS + Barman env vars passed
in via SSH `-o SetEnv`. Phase C's schedule runner finds the leader
through Patroni's REST API, SSHes in as `postgres`, and invokes
the runner with the chosen destination's credentials. The Barman
container only handles local backups (`barman backup orbital`) and
the WAL mirror.

Restore from a cloud backup uses `barman-cloud-restore`:
```bash
sail exec barman gosu barman barman-cloud-restore \
    --endpoint-url "$PRIMARY_S3_ENDPOINT" \
    "s3://$PRIMARY_S3_BUCKET" orbital <backup-id> <target-dir>
```
PITR with cloud WAL is the same plus `--target-time` and
`barman-cloud-wal-restore` as the `restore_command`.

### SeaweedFS

Replication=001 gives you one copy per write across volume servers. No
separate backup process — the replication IS the backup. For disaster
recovery beyond one-site, add an off-site volume server and set
replication=011 or higher.

### Valkey

Cache + queue + transient session data. No backup. On catastrophic
Valkey loss, sessions log users out and queued jobs need to be
re-triggered. Horizon replays will cover the latter in most cases.

### SeaweedFS metadata (filer store)

Filer metadata lives in Valkey database #2. Gets wiped with Valkey —
which means the blob layer's path-to-volume mapping is lost. Running
`weed filer.cat` recovery is possible but painful. In practice, treat
a full Valkey loss as a "restore S3 from off-site backup" event; once
per-client off-site sync is wired, this stops being scary.

---

## Testing shortcuts

Non-HA but useful to know about while you're set up for admin work.

### Operator-dialed DID simulation

When `TELEPHONY_INTERNAL_DID_SIMULATION=true` in `.env`, an
operator seated at any softphone can dial a client's external DID
(e.g. `15550000001`) and the call takes the exact same routing
path a real inbound SIP trunk call would. Handy for validating:

- A new routing rule routes to the right queue / agent / mailbox
- A voicemail mailbox accepts messages and emails work end-to-end
- An AI persona answers and runs its intake flow
- A template client behaves as designed

**How it works.** `AsteriskConfigService` injects an extension
pattern `_1NXXNXXXXXX` into the `[internal]` dialplan that
unconditionally `Goto`'s `[from-trunk]` with the dialed digits as
`${EXTEN}`. From there the dispatcher picks the matching routing
rule exactly as if the call had arrived on a SIP trunk.

**Turn it off in production.** Operators should never be able to
self-originate calls that look like they came from outside the
building — this only fires when the flag is true, but the flag
defaults to false and should stay false on any real deployment.

The feature lives in `config/telephony.php`
(`'asterisk.internal_did_simulation'`) so a future admin surface
can flip it per-install without a redeploy.

---

## Troubleshooting

| Symptom                                     | First place to look                                              |
|---------------------------------------------|------------------------------------------------------------------|
| Operator sees "Reconnecting…" on softphone  | HAProxy `asterisk_wss_be` — is the node they're pinned to drained? |
| Inbound call returns 503 at the trunk       | Kamailio `dispatcher.list` — any nodes UP? Check `AsteriskBackend` rows |
| Cache misses spike, sessions log out        | Valkey failover in progress. Check Failover page — Sentinel card  |
| Writes block, admin UI hangs                | Patroni is mid-switchover. Check Failover → Postgres card         |
| Recording not landing in S3                 | SeaweedFS — are both filers reachable? `haproxy:8333` healthy?    |
| Grafana dashboard shows "no data"           | Prometheus side. Both `prometheus_be` servers UP?                 |
| Bell icon pops database notifications but no slide-in toast | Queue worker stopped. See Reverb pub/sub path and supervisord     |
| New Asterisk node doesn't appear            | Container started before the DB row? Check kamailio logs for `pack_dest` |

---

## Known gaps

These aren't blockers but are worth knowing about:

- **No "pause auto-failover" toggle** on Patroni or Sentinel in the UI.
  Shell out to `patronictl pause` / `SENTINEL SET ... failover-timeout`
  if you need to do disruptive work without the cluster reacting.
- **No per-master / per-volume drain** for SeaweedFS. `docker stop` is
  the interface; replication handles the rest.
- **No VRRP control** from the app. Keepalived master/backup switching
  is infrastructure-layer.
- **No LiveKit drain**. Restart drops in-flight rooms on that instance.
- **No cross-region DR**. Single-site only. DR + failover is a later
  phase.

---

## Known benign log noise

Errors that look scary but are cosmetic — catalogued here so on-call
doesn't chase them during an incident. If you see one of these,
confirm it matches the description and move on. If the rate or
pattern looks different from what's documented, investigate.

### Patroni — `ConnectionResetError: [Errno 104] Connection reset by peer`

**Where:** `orbital-patroni-{1,2,3}-1` container logs, stack ending in
`socketserver.py ... sendall(b)` → `ConnectionResetError`.

**Cause:** HAProxy probes Patroni's REST API (`/`, `/leader`,
`/replica`) every few seconds to decide which node gets Postgres
writes. HAProxy reads the HTTP status line + headers to make its
routing decision and closes the socket with RST — it doesn't need
the JSON body. Patroni's response writer is already partway through
`wfile.write(body.encode('utf-8'))` when the socket closes, so
`sendall()` raises. The health check itself *succeeded*; the check
result was derived from the status line Patroni had already sent.

**Why it's safe to ignore:**

- Postgres replication runs over a separate streaming channel between
  the Patroni-managed Postgres instances. Nothing to do with the REST
  API.
- Leader election runs over etcd, not the REST API.
- The Patroni main loop keeps humming between tracebacks — you'll see
  `"no action. I am (...), following a leader (...)"` interleaved with
  the errors.

**When to care:** if the traceback appears *without* a `"no action"`
heartbeat for more than ~30s, Patroni's main loop is stuck and that's
a real problem. Check etcd quorum and Patroni's own health next.

**Upstream:** a known Patroni issue; the fix (silencing the write
exception in the API handler) has been proposed multiple times but
never merged because it's cosmetic.

---

## Emergency checklist

When something is on fire:

1. Open **Failover Central**. Note which tiers show red or yellow.
2. Check the SystemStatusBar (topbar) — that's a broader reachability
   probe, including the app tier.
3. Tail the logs of the red tier's containers (`docker logs
   orbital-<service>-1 --tail=100`).
4. If writes are blocked and Patroni is red, check etcd first — no
   etcd quorum means no leader election.
5. If the whole site is down, check nginx-tls and the public VIP. In
   dev that's `orbital.test:443`; in prod it's the keepalived VIP.

Every destructive action from the admin UI is logged to
`failover_audit_logs`. Pull that table after an incident for the
timeline of who did what.
