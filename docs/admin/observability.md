# Observability

What Orbital reports about itself, and the two optional integrations you can
point at your own tooling: **tracing** over OTLP (Grafana Tempo and anything
else that speaks it) and **error reporting** over Sentry's ingest API
(GlitchTip, Sentry, or a compatible service).

> **Metrics and logs need no setup.** Prometheus scraping, the four
> provisioned Grafana dashboards, and Loki log shipping are part of the
> stack and running already — see [High Availability](high-availability.md)
> for the tier itself. This page is about the two things that are **off
> until you turn them on**.

Alerting is on by default but delivers nothing until you give it a
destination. The two integrations below are genuinely optional: with
them off, no client is constructed, no exporter is built, and no event
listeners are attached to the query path. The cost is a config read.

---

## The picture at a glance

```
ALWAYS ON     Prometheus (metrics) · Grafana (dashboards) · Loki (logs)
              Alertmanager (routing) -- needs a destination to deliver
OPTIONAL      Tracing      -> OTLP/HTTP -> Tempo, OTel Collector, Grafana Cloud
OPTIONAL      Errors       -> Sentry envelope -> GlitchTip, Sentry
```

| Signal  | Answers | Backend | Default |
|---------|---------|---------|---------|
| Metrics | "Is it healthy, and how much of it is there?" | Prometheus | On |
| Logs    | "What did it say when it happened?" | Loki | On |
| Alerts  | "Something is wrong and nobody is looking" | Alertmanager | On, undelivered |
| Traces  | "Where did this request spend its time?" | Tempo (any OTLP) | **Off** |
| Errors  | "What broke, on which build, how often?" | GlitchTip (any Sentry API) | **Off** |

---

## Alerting

Dashboards make problems visible to someone who is looking. Alerts make
them visible to someone who isn't.

**Prometheus decides what is wrong; Alertmanager decides who hears about
it.** Prometheus evaluates `docker/prometheus/rules/*.yml` on every
scrape interval and, when a rule matches for long enough, POSTs the
firing alert to Alertmanager. Prometheus cannot send an email or call a
webhook — that is entirely Alertmanager's job, along with the parts that
make alerting bearable:

| Behaviour | Why it matters here |
|-----------|---------------------|
| **Dedupe** | Both Prometheus nodes evaluate the same rules and both fire. You get one notification. |
| **Grouping** | A data-tier outage trips eight rules. One message, not eight. |
| **Inhibition** | While Asterisk is reported down, "queue not draining" is a symptom. It is suppressed so nobody chases three problems that are one problem. |
| **Silences** | Set one before draining an Asterisk node, so planned work is quiet. |
| **Repeat interval** | Re-notifies every 4h while still firing (1h for critical), so an ignored alert doesn't become a forgotten one. |

Alertmanager runs by default and **ships with no destination
configured.** In that state alerts still group, deduplicate, and are
visible at `http://localhost:9093` — they are simply not sent anywhere.
That is deliberate: an install that has not chosen a destination should
not be emailing a placeholder address.

### Turning delivery on

`ALERT_EMAIL_TO` is the switch. Set it and the email receiver is
rendered into the config; leave it blank and no email block is written
at all, because Alertmanager refuses to start on an empty `to:`.

```
ALERT_EMAIL_TO=ops@example.com
ALERT_SMTP_SMARTHOST=smtp.example.com:587
ALERT_SMTP_FROM=alerts@example.com
ALERT_SMTP_USERNAME=            # blank omits AUTH entirely
ALERT_SMTP_PASSWORD=
ALERT_SMTP_REQUIRE_TLS=true
```

**Prefer SMTP over anything that runs through Orbital itself.** An alert
route that passes through the application being monitored is not an
alert route: when Laravel is down, or Valkey is down, or Postgres has
lost quorum, that is precisely when the notification still has to get
out. Email leaves the box.

A generic webhook is available alongside it as the escape hatch for
anything without a native receiver:

```
ALERT_WEBHOOK_URL=https://hooks.example.com/orbital
```

> **Webhook bodies cannot be templated.** Alertmanager's webhook
> receiver sends a fixed JSON schema — unlike its email receiver, there
> is no way to format it. A service that expects readable text (ntfy,
> for example) will show raw JSON. For phone push via ntfy, the tidier
> route is to point `ALERT_SMTP_SMARTHOST` at ntfy's own SMTP listener:
> you get a formatted message and reuse the receiver you already
> configured, instead of running a translating bridge.

Alertmanager has no environment-variable expansion of its own, so
`docker/alertmanager/` builds a small image that renders the config with
`envsubst` at start — the same pattern `haproxy`, `valkey-ha`, and
`seaweed-ha` already use. The rendered config is validated with
`amtool check-config` before the process starts, so a mistake surfaces
at boot rather than as alerts quietly never arriving.

### What is alerted on

Seventeen rules across five groups, all written against metrics that
already exist — nothing here needed new instrumentation.

| Group | Covers |
|-------|--------|
| `orbital-pipeline` | The alerting itself: stale metric pushes, an app instance not answering |
| `orbital-telephony` | Asterisk down, no SIP registrations, stuck call counts, no active rtpengine |
| `orbital-work` | Unrouted email, endpoints without a sender pool, undelivered texts, queues not draining, failed jobs |
| `orbital-backups` | No recent successful backup, none ever successful, an archive that shrank sharply. See [Backups](backups.md) |
| `orbital-app` | 5xx rate over 1%, p95 response time over 5s |

Two of those deserve explanation.

**`OrbitalMetricsPipelineStale` is the alert about the alerts.**
Platform and telephony metrics are *pushed* to Pushgateway once a minute
by `orbital:collect-metrics`, and Pushgateway serves the last value it
received **forever**. If the scheduler dies, every one of those series
freezes at its last healthy reading and every rule built on them goes
quietly green — the most dangerous failure mode alerting has, because
silence is indistinguishable from health. This rule watches
`push_time_seconds` and inhibits every pushed-source alert while it is
firing, so you are told the numbers are stale rather than being shown
stale numbers.

**`OrbitalCallsInProgressStuck` uses a floor, not a threshold.** Real
concurrency fluctuates and falls back to baseline between bursts. A
count that never drops is the signature of lost hangup events leaving
`call_logs` rows open, not of a busy day, so the rule alerts on
`min_over_time` rather than on the current value.

### Adding a rule

Drop a file in `docker/prometheus/rules/` — the `rule_files` glob picks
it up, no compose change needed — then reload:

```
curl -X POST http://localhost:9090/-/reload
```

Check metric names against
`app/Services/Metrics/PlatformMetricsCollector.php` and
`TelephonyMetricsCollector.php` first. A rule against a misspelled
metric never fires, and looks exactly like a healthy system.

---

## Read this before you enable either

Orbital handles other people's callers. An exception report is a copy of
whatever the process was holding when it failed, and a span is a record of
what a request touched. Left naive, both would carry caller names, phone
numbers, message bodies, DTMF digits, recording URLs, and intake fields.

Orbital does not leave them naive:

- **Errors.** `send_default_pii` is forced off and is not exposed as a
  setting, so request bodies, cookies, and client IPs are never attached.
  Every event then passes through `App\Services\Observability\Scrubber`,
  which redacts by key substring — `phone`, `caller_phone`, `from_phone`
  and `phone_number` all match one rule, because listing exact spellings is
  how a scrubber quietly stops working. User identity is rebuilt as an
  internal id plus a client id; never a name, email, or IP.
- **Traces.** Spans carry identifiers, never content. The database seam
  records the SQL statement and deliberately **not** its bindings — the
  statement is the shape of the work, the bindings are the caller's phone
  number. Outbound HTTP spans record host and method, never the full URL,
  because those query strings carry message SIDs and API keys.

**What that does not solve:** pointing either integration at a hosted
service exports your clients' operational data to a third party. If you run
Orbital under a BAA, a DPA, or any contract that constrains sub-processors,
that is a decision to make before you paste a DSN, not after. Self-hosting
both backends avoids the question entirely.

---

## Error reporting

**System → Settings → Error Reporting**, or `.env`.

| Setting | Env | Notes |
|---------|-----|-------|
| Enabled | `ERROR_REPORTING_ENABLED` | Does nothing without a DSN. |
| DSN | `ERROR_REPORTING_DSN` | Stored encrypted when set in the UI. |
| Environment label | `ERROR_REPORTING_ENVIRONMENT` | Blank uses `APP_ENV`. |
| Sample rate | `ERROR_REPORTING_SAMPLE_RATE` | Leave at `1.0`. |

**The toggle alone does not enable it.** Enabled plus a blank DSN counts as
off. That is deliberate: the alternative builds a client that discards
everything while you believe errors are being captured, which is the one
state worse than being switched off.

For the same reason, Orbital forces the vendor SDK's own DSN to null when
the integration is off — a stray `SENTRY_LARAVEL_DSN` inherited from a base
image or a CI runner cannot switch on off-site reporting behind you.

Every event is tagged with the release (`ORBITAL_VERSION`), the release
channel, and the short commit, so a regression can be attributed to a
deploy. Ordinary web failures — validation errors, 404s, auth redirects,
CSRF mismatches — are never reported; they are the normal shape of a web
application and reporting them buries the things that are not.

### Getting a DSN

- **GlitchTip** — Settings → Projects → your project → DSN.
- **Sentry** — Settings → Projects → Client Keys.

Hosted and self-hosted DSNs differ only in the host, so either works
unchanged.

---

## Tracing

**System → Settings → Tracing**, or `.env`.

| Setting | Env | Notes |
|---------|-----|-------|
| Enabled | `TRACING_ENABLED` | Off by default. |
| OTLP endpoint | `TRACING_ENDPOINT` | Full path. `http://tempo:4318/v1/traces` |
| Service name | `TRACING_SERVICE_NAME` | Defaults to `orbital`. |
| Sample ratio | `TRACING_SAMPLE_RATIO` | Defaults to `0.05`. |
| Authentication | `TRACING_AUTH_MODE` | `none`, `basic`, or `bearer`. |
| Username | `TRACING_AUTH_USERNAME` | Grafana Cloud: the numeric instance ID. |
| Token | `TRACING_AUTH_SECRET` | Blank sends no `Authorization` header. |

**HTTP only, on 4318, with the full `/v1/traces` path.** gRPC on 4317 is not
supported and is not an oversight: it would need `ext-grpc` in the PHP image
and a `mode tcp` HAProxy frontend instead of the ordinary HTTP ones the rest
of the observability tier uses.

### What gets traced

Five seams, each individually switchable:

| Seam | Span | Env |
|------|------|-----|
| HTTP kernel | One root span per request, named by route pattern | always |
| Database | One span per query, capped per trace | `TRACING_CAPTURE_DB` |
| Queue jobs | One root span per job in the worker | `TRACING_CAPTURE_QUEUE` |
| Outbound HTTP | One span per call, plus `traceparent` propagation | `TRACING_CAPTURE_HTTP_CLIENT` |
| Console | One root span per artisan command | always |

Instrumentation is **manual**, not OpenTelemetry auto-instrumentation. The
auto version needs the `opentelemetry` PECL extension, which is not in the
Ondrej PPA — it would mean a `pecl` build in `docker/8.4/Dockerfile` and a
differently-shaped one in the production `Dockerfile`, in an image whose
CVE surface is a design constraint. The five seams above cover what Orbital
actually does.

### Sampling

The default of 5% is not timidity. A call center serves a lot of requests,
most of them Livewire polls, and a backend ingesting all of them costs a
great deal without answering anything more.

Sampling is **parent-based**: the decision is taken once at the edge and
respected by everything downstream, so a traced request keeps its queue
jobs and its outbound calls. Sampling each hop independently would produce
traces with holes in them, and a missing span reads as work that never
happened.

A ratio of `0.0` keeps the pipeline wired but silent — useful for
confirming an endpoint and credential before turning real traffic on.

### Bounds and failure

`TRACING_MAX_DB_SPANS` (default 100) caps database spans per trace. An
accidental N+1 can run thousands of queries in one request; uncapped, that
breaches the backend's per-trace span limit, which usually rejects the
**whole** trace — so a performance bug would destroy the evidence of
itself. Truncating is better: the first hundred show the pattern.

**Tracing never breaks a request.** A bad endpoint or an unreachable
collector is swallowed, logged once, and then the pipeline stands down for
the rest of the process rather than retrying a dead connection on every
span. A monitoring problem must not become an outage.

---

## Running Tempo yourself

The compose stack ships Tempo as an **opt-in overlay**. It is not in the
base stack, because most installs will never turn tracing on, and it is not
in the Helm chart, which assumes the cluster already has an observability
stack — the same posture the chart takes toward Prometheus and Grafana.

**Create the bucket first.** Tempo does not, and it fails quietly: the
distributor keeps accepting spans while nothing is durable.

```
docker compose exec seaweed-filer-1 \
    weed shell -c "s3.bucket.create -name tempo-traces"
```

Then append the overlay to `COMPOSE_FILE` in `.env`:

```
COMPOSE_FILE=docker-compose.yml:compose/ha-data.yml:compose/ha-app.yml:compose/ha-telephony.yml:compose/ha-edge.yml:compose/ha-obs.yml:compose/obs-tracing.yml
```

```
make up
TRACING_ENABLED=true
TRACING_ENDPOINT=http://tempo:4318/v1/traces
```

Traces land in the SeaweedFS bucket `tempo-traces`, beside Loki's
`loki-chunks`, with 7-day retention to match. Loading `compose/ha-obs.yml`
also brings up `tempo-2` against the same bucket, so either node can answer
any query.

### Correlation

The Grafana datasources are provisioned with both directions wired:

- A trace id in a **log line** renders as a link into Tempo.
- A **span** links back to the logs of the same service over its own time
  range.

This is the half that makes tracing worth having. Without it you have two
UIs and no way to get from "this request errored" to "here is where it
spent its time".

One caveat on the log side: Orbital's log lines do not yet carry the trace
id, so `filterByTraceID` is off in the Tempo datasource. Turn it on once
they do — leaving it on now returns an empty panel that looks broken.

---

## Kubernetes

The chart ships **no** observability components. Set the endpoints in
`values.yaml` under `observability`, and put the credentials in the
`app-secrets` Secret, which every pod already reads:

```yaml
observability:
  tracing:
    enabled: true
    endpoint: "http://tempo.monitoring.svc.cluster.local:4318/v1/traces"
    sampleRatio: 0.05
  errors:
    enabled: true
```

```
kubectl create secret generic {release}-orbital-app-secrets \
  --from-literal=ERROR_REPORTING_DSN='<dsn>' \
  --from-literal=TRACING_AUTH_SECRET='<token>'
```

Changing either rolls the app pods, via the checksum annotation on the
`laravel` Deployment. That is wanted — see below.

---

## Restarting after a change

Horizon workers, Reverb, and the Python agent worker resolve configuration
**once, at boot**. A change made in System → Settings reaches HTTP
requests immediately and background workers only after a restart, which is
why both settings sections ask for one.

The settings page offers the Horizon restart directly. The agent worker is
configured by environment variables only — it has no path for fetching
infrastructure settings from the API — so it is restarted the usual way.

---

## See also

- [Monitoring](monitoring.md) — system status, call logs, and failed inbound mail
- [High Availability](high-availability.md) — the observability tier itself
- `docker/prometheus/rules/orbital.yml` — every alert, with the reasoning
- `config/observability.php` — every setting, with the reasoning
- `config/metrics.php` — why deployment-wide numbers are pushed, not scraped
- [Environment Variables](../reference/environment.md) — which settings live in the UI and which are
  deliberately `.env`-only
