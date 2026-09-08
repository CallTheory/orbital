<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Metrics endpoint
    |--------------------------------------------------------------------------
    |
    | GET /metrics serves the Prometheus exposition for THIS app instance.
    | The compose network keeps it internal (the app container's port is
    | never host-bound; only nginx-tls and Prometheus can reach it), so a
    | token is optional. Set METRICS_TOKEN when the app is reachable from
    | anywhere less trusted — Prometheus then needs a matching
    | `authorization` credential in its scrape config.
    |
    */
    'enabled' => (bool) env('METRICS_ENABLED', true),
    'token' => env('METRICS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Instance identity
    |--------------------------------------------------------------------------
    |
    | Every app replica accumulates its OWN HTTP counters under this name,
    | so scraping laravel and laravel-2 yields two independent series
    | rather than each reporting the shared total twice. Defaults to the
    | container hostname, which compose and Kubernetes both make unique.
    |
    */
    'instance' => env('METRICS_INSTANCE') ?: (gethostname() ?: 'unknown'),

    /*
    |--------------------------------------------------------------------------
    | HTTP request metrics
    |--------------------------------------------------------------------------
    |
    | Recorded by the RecordHttpMetrics middleware into Valkey and read
    | back at scrape time.
    |
    | Labels are deliberately coarse — surface (admin/operator/portal/api),
    | method, and status class. Per-route labels were considered and
    | rejected: Filament generates a lot of routes, tenant-scoped URLs
    | carry IDs, and a cardinality explosion in Prometheus is much more
    | expensive to undo than it is to avoid. Per-route latency questions
    | are better answered by Telescope/Pulse, which are already wired.
    |
    */
    'http' => [
        'enabled' => (bool) env('METRICS_HTTP_ENABLED', true),

        // Histogram bucket upper bounds in seconds.
        'buckets' => [0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],

        // Counters live in Valkey and are re-read on every scrape. They
        // expire if an instance stops serving traffic entirely, so a
        // decommissioned replica's series goes stale rather than
        // lingering forever.
        'ttl_hours' => (int) env('METRICS_HTTP_TTL_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pushgateway
    |--------------------------------------------------------------------------
    |
    | Platform-wide metrics (per-client call/message/email counts, queue
    | depth, Asterisk channel counts) are gathered by the scheduled
    | `orbital:collect-metrics` command and pushed here.
    |
    | Why push instead of adding them to /metrics: those numbers are
    | global facts about the whole deployment, not about one replica. If
    | every app instance exposed them, Prometheus would record N copies
    | of the same value and every dashboard query would need a
    | `max without(instance)` wrapper to avoid multiplying the totals.
    | One scheduled writer (guarded by onOneServer()) publishes one
    | series. See routes/console.php.
    |
    */
    'pushgateway' => [
        'enabled' => (bool) env('METRICS_PUSHGATEWAY_ENABLED', true),
        'url' => env('METRICS_PUSHGATEWAY_URL', 'http://pushgateway:9091'),
        'job' => env('METRICS_PUSHGATEWAY_JOB', 'orbital_platform'),
        'timeout' => (int) env('METRICS_PUSHGATEWAY_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-client metric labels
    |--------------------------------------------------------------------------
    |
    | Platform metrics carry a `client` label so the tenant-facing portal
    | charts can filter by team. On a deployment with thousands of
    | clients that's a lot of series, so it can be turned off; the
    | platform-wide totals stay either way.
    |
    */
    'per_client_labels' => (bool) env('METRICS_PER_CLIENT_LABELS', true),

];
