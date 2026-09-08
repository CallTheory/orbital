<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [

    /*
    |--------------------------------------------------------------------------
    | Optional observability integrations
    |--------------------------------------------------------------------------
    |
    | Two integrations, both OFF by default and both pointed wherever the
    | operator wants: a hosted service or something they run themselves.
    | Orbital does not care which, because it only ever speaks the wire
    | protocol — Sentry's envelope format for errors, OTLP for traces —
    | and every backend worth using speaks one of those.
    |
    | Off is genuinely off. When `enabled` is false no client is built,
    | no exporter is constructed, and no handler is installed; the cost
    | is a config read. Nothing in Orbital is gated on these being on.
    |
    | Both can be configured from Settings → Platform rather than the
    | .env file. Long-running processes (Horizon workers, Reverb, the
    | agent worker) resolve config once at boot, so a change made in the
    | UI reaches HTTP requests immediately and workers only after a
    | restart — which is why the settings entries ask for one.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Error reporting (Sentry-compatible)
    |--------------------------------------------------------------------------
    |
    | Unhandled exceptions, with a stack trace and the release they
    | happened on. GlitchTip and Sentry both work — GlitchTip implements
    | Sentry's ingest API, so the DSN is the only thing that changes.
    |
    | This is a call-center platform, so what gets sent matters as much
    | as whether anything does. Caller names, phone numbers, message
    | bodies, DTMF, and recording URLs all pass through this application
    | constantly, and an error report is a copy of whatever the process
    | was holding. `send_default_pii` is therefore forced off rather than
    | exposed as a setting, and every event goes through
    | App\Services\Observability\Scrubber on the way out. Turning this on
    | still means exporting stack traces to wherever you pointed it —
    | if that is somebody else's SaaS, check that against whatever you
    | promised your own clients before you enable it.
    |
    */
    'errors' => [
        'enabled' => (bool) env('ERROR_REPORTING_ENABLED', false),

        // GlitchTip: Settings → Projects → your project → DSN.
        // Sentry: Settings → Projects → Client Keys.
        // Self-hosted and hosted DSNs differ only in the host.
        'dsn' => env('ERROR_REPORTING_DSN'),

        // Which deployment reported it. Defaults to the Laravel
        // environment, which is almost always what you want; override
        // when two installs share one project (staging-eu, staging-us).
        'environment' => env('ERROR_REPORTING_ENVIRONMENT'),

        // Fraction of errors actually sent, 0.0–1.0. Left at 1.0 on
        // purpose: unlike traces, errors are rare and each one is a
        // distinct thing that went wrong. Turn it down only if a
        // pathological loop is flooding the project.
        'sample_rate' => (float) env('ERROR_REPORTING_SAMPLE_RATE', 1.0),

        // Exception classes never reported. Not errors — the ordinary
        // shape of a web application, and reporting them buries the
        // things that are.
        'ignore_exceptions' => [
            AuthenticationException::class,
            AuthorizationException::class,
            ModelNotFoundException::class,
            TokenMismatchException::class,
            ValidationException::class,
            HttpException::class,
            NotFoundHttpException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing (OpenTelemetry over OTLP)
    |--------------------------------------------------------------------------
    |
    | Where a request actually spent its time, across the boundaries a
    | single log line cannot cross. Grafana Tempo is the backend this was
    | built against, but nothing here is Tempo-specific — it speaks OTLP
    | over HTTP, so an OpenTelemetry Collector, Jaeger, Honeycomb, or
    | Grafana Cloud all work by changing the endpoint.
    |
    | Instrumentation is MANUAL, at five seams (HTTP kernel, database,
    | queue jobs, outbound HTTP, console commands). The alternative,
    | OpenTelemetry's auto-instrumentation, needs the `opentelemetry`
    | PECL extension, which is not in the Ondrej PPA — it would mean a
    | pecl build in docker/8.4/Dockerfile (Ubuntu + PPA) and a
    | differently-shaped one in the production Dockerfile
    | (php:8.4-fpm-alpine), in an image whose header names Trivy
    | hit-count as a design constraint. Manual instrumentation covers
    | what Orbital actually does; the extension can be added later
    | without invalidating any of this.
    |
    | Same rule as error reporting, and for the same reason: spans carry
    | IDs, never content. A trace showing that a message send took 900ms
    | is useful. A trace containing the message is a copy of somebody's
    | text sitting in a tracing backend.
    |
    */
    'tracing' => [
        'enabled' => (bool) env('TRACING_ENABLED', false),

        // Full path, not just the host — backends disagree about it.
        // Self-hosted Tempo and the OTel Collector take /v1/traces on
        // 4318; Grafana Cloud gives you a tenant-specific URL.
        'endpoint' => env('TRACING_ENDPOINT', 'http://tempo:4318/v1/traces'),

        // OTLP over HTTP, never gRPC: gRPC needs ext-grpc in the image
        // and a `mode tcp` HAProxy frontend rather than the ordinary
        // HTTP ones the rest of the observability tier uses.
        // 'http/protobuf' is the compatible default; 'http/json' is
        // easier to read off the wire when debugging the exporter.
        'protocol' => env('TRACING_PROTOCOL', 'http/protobuf'),

        // How this install identifies itself in the trace backend. Worth
        // changing only when several Orbital installs report to one
        // backend and need telling apart.
        'service_name' => env('TRACING_SERVICE_NAME', 'orbital'),

        // Fraction of traces recorded, 0.0-1.0. Deliberately NOT 1.0: a
        // call center serves a lot of requests, most of them Livewire
        // polls, and a backend that ingests every one of them is
        // expensive and no more informative. Sampling is parent-based,
        // so a sampled request keeps its queue jobs and outbound calls
        // — you get whole traces, not fragments.
        'sample_ratio' => (float) env('TRACING_SAMPLE_RATIO', 0.05),

        'timeout' => (float) env('TRACING_TIMEOUT', 5.0),

        /*
        | Self-hosted Tempo behind a private network usually needs no
        | credential at all. Grafana Cloud uses basic auth with the
        | instance ID as the username. A token-proxied setup uses a
        | bearer. Those three cover everything seen in practice.
        */
        'auth' => [
            'mode' => env('TRACING_AUTH_MODE', 'none'), // none | basic | bearer
            'username' => env('TRACING_AUTH_USERNAME'),
            'secret' => env('TRACING_AUTH_SECRET'),
        ],

        /*
        | Which seams produce child spans. All on by default — a trace
        | missing its database time answers the wrong question — but each
        | can be turned off if it proves noisy on a given install.
        */
        'capture' => [
            'db' => (bool) env('TRACING_CAPTURE_DB', true),
            'queue' => (bool) env('TRACING_CAPTURE_QUEUE', true),
            'http_client' => (bool) env('TRACING_CAPTURE_HTTP_CLIENT', true),
        ],

        // Ceiling on database spans in a single trace. A Filament page
        // runs a lot of queries and an accidental N+1 runs thousands;
        // without a cap one pathological request can pin the exporter
        // and blow the backend's per-trace span limit, which usually
        // means the WHOLE trace is rejected. Truncating is better than
        // losing it: the first hundred are enough to see the pattern,
        // and the span count itself tells you it was truncated.
        'max_db_spans' => (int) env('TRACING_MAX_DB_SPANS', 100),
    ],

];
