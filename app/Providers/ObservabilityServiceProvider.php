<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Observability\Scrubber;
use App\Services\Observability\Tracer;
use App\Support\Observability;
use App\Support\Release;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;
use Sentry\Event as SentryEvent;
use Sentry\EventHint;
use Sentry\State\Scope;
use Sentry\UserDataBag;

/**
 * Wires the optional error-reporting integration, and nothing else when
 * it is switched off.
 *
 * There is one place an operator configures this — `observability.errors`,
 * fed by env or by Settings → Platform — and this provider is what maps
 * it onto the Sentry SDK's own config. The alternative, publishing
 * `config/sentry.php` and asking operators to keep two files agreeing
 * with each other, is how an integration ends up enabled in one file and
 * disabled in the other.
 *
 * The mapping runs from an `$this->app->booting()` callback rather than
 * from register() or boot(), and the timing is the whole trick:
 *
 *   - Package providers (including Sentry's) register BEFORE app
 *     providers, so anything set in our register() would already be too
 *     late to be seen as a default — but Sentry builds its client
 *     lazily and reads config in boot(), so it is not too late overall.
 *   - RuntimeConfigOverrideProvider applies the platform_settings
 *     overrides from ITS booting callback, and it is listed before this
 *     provider in bootstrap/providers.php, so its callback runs first.
 *     Reading `observability.errors.*` here therefore sees the values an
 *     operator typed into the admin UI, not just the .env defaults.
 *   - All booting callbacks fire before ANY provider's boot(), so
 *     Sentry's boot() sees the finished config.
 *
 * Get that order wrong and the symptom is subtle: the integration works
 * when configured by .env and silently ignores the admin UI.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Scrubber::class);
        $this->app->singleton(Tracer::class);

        $this->app->booting(function (): void {
            $this->configureErrorReporting();
        });
    }

    /**
     * Runs after Sentry's own boot(), which is when the hub exists and
     * can be given scope. Doing this from the booting callback above
     * would resolve the client mid-configuration, before the DSN it is
     * supposed to use has been written.
     */
    public function boot(): void
    {
        if (Observability::errorsEnabled()) {
            $this->tagScope();
        }

        $this->registerTracingSeams();
    }

    /**
     * Translate our settings into the Sentry SDK's, or defuse it.
     *
     * Disabled is expressed as a null DSN rather than by skipping this
     * method: the package is auto-discovered and will have merged its
     * own defaults, which read SENTRY_LARAVEL_DSN straight out of the
     * environment. A stray env var must not be able to switch on
     * off-site reporting behind the operator's back.
     *
     * Public so tests can re-run the mapping after changing config — by
     * the time a test body executes, providers have long since booted,
     * and the alternative is asserting on a booting callback that can
     * never fire again in that process.
     */
    public function configureErrorReporting(): void
    {
        if (! Observability::errorsEnabled()) {
            config(['sentry.dsn' => null]);

            return;
        }

        $scrubber = $this->app->make(Scrubber::class);

        config([
            'sentry.dsn' => Observability::errorsDsn(),
            'sentry.environment' => Observability::environment(),

            // Ties every event to a build. Without it a regression
            // report cannot say which deploy introduced it, which is
            // most of what an error tracker is for. Same string as
            // orbital_build_info and the About page.
            'sentry.release' => Release::version(),

            'sentry.sample_rate' => (float) config('observability.errors.sample_rate', 1.0),

            // Not a setting, and not negotiable. See config/observability.php.
            'sentry.send_default_pii' => false,

            // Exactly one tracing pipeline, and it is not this one.
            // Sentry's performance monitoring would produce a second,
            // half-populated set of traces alongside the OTLP exporter,
            // and two partial answers to "what did this request do" is
            // worse than one complete one.
            'sentry.traces_sample_rate' => 0.0,
            'sentry.profiles_sample_rate' => 0.0,

            'sentry.ignore_exceptions' => (array) config('observability.errors.ignore_exceptions', []),

            'sentry.before_send' => function (SentryEvent $event, ?EventHint $hint) use ($scrubber): ?SentryEvent {
                return $this->sanitise($event, $scrubber);
            },
        ]);
    }

    /**
     * Last gate before an event leaves the process.
     *
     * Everything structured that Sentry is about to send goes through
     * the scrubber, and the user identity is rebuilt from scratch rather
     * than filtered — a whitelist is the only version of this that stays
     * correct when the SDK adds a field.
     */
    private function sanitise(SentryEvent $event, Scrubber $scrubber): SentryEvent
    {
        $event->setExtra($scrubber->scrub($event->getExtra()));
        $event->setRequest($scrubber->scrub($event->getRequest()));

        foreach ($event->getContexts() as $name => $context) {
            if (is_array($context)) {
                $event->setContext($name, $scrubber->scrub($context));
            }
        }

        // Who, not which person. An internal user id and their client is
        // enough to reproduce a bug and to tell whether it affects one
        // tenant or all of them; a name, email, or IP is not needed for
        // either and is somebody's personal data sitting in a third-party
        // system.
        $user = auth()->user();

        if ($user) {
            $identity = UserDataBag::createFromUserIdentifier((string) $user->getAuthIdentifier());
            $identity->setMetadata('team_id', (string) ($user->current_team_id ?? ''));
            $event->setUser($identity);
        } else {
            $event->setUser(null);
        }

        return $event;
    }

    /**
     * Attach the tracing seams: database, queue jobs, outbound HTTP,
     * console commands.
     *
     * Registered only when tracing is on. Laravel's event listeners are
     * cheap but not free, and the point of "off costs nothing" is that a
     * disabled integration adds no listeners to a hot path that runs on
     * every query of every request.
     *
     * Each seam records a COMPLETED span from an event that fires after
     * the work is done and carries its own duration. That is why there
     * is no start/stop pairing here and no risk of a leaked span: if the
     * event never fires, nothing was ever opened.
     */
    private function registerTracingSeams(): void
    {
        $tracer = $this->app->make(Tracer::class);

        if (! $tracer->enabled()) {
            return;
        }

        $capture = (array) config('observability.tracing.capture', []);

        if ($capture['db'] ?? true) {
            $this->traceDatabase($tracer);
        }

        if ($capture['queue'] ?? true) {
            $this->traceQueue($tracer);
        }

        if ($capture['http_client'] ?? true) {
            $this->traceOutboundHttp($tracer);
        }

        $this->traceConsole($tracer);
    }

    /**
     * One span per query.
     *
     * The SQL text is recorded; the bindings are NOT. A statement is the
     * shape of the work and is what makes a slow trace diagnosable. The
     * bindings are the caller's phone number, the body of somebody's
     * text, and the contents of an intake form — the exact things that
     * must not leave the platform. Laravel hands both to this listener
     * and taking only the first is the whole decision.
     */
    private function traceDatabase(Tracer $tracer): void
    {
        DB::listen(function (QueryExecuted $query) use ($tracer): void {
            if (! $tracer->hasActiveTrace() || ! $tracer->acceptDbSpan()) {
                return;
            }

            $tracer->record(
                name: 'db '.$this->sqlVerb($query->sql),
                durationSeconds: $query->time / 1000,
                attributes: [
                    TraceAttributes::DB_SYSTEM_NAME => $query->connection->getDriverName(),
                    TraceAttributes::DB_NAMESPACE => $query->connection->getDatabaseName(),
                    TraceAttributes::DB_QUERY_TEXT => $query->sql,
                ],
                kind: SpanKind::KIND_CLIENT,
            );
        });
    }

    /**
     * One span per queued job, in the worker's own trace.
     *
     * A worker process has no inbound HTTP request to hang spans off, so
     * the job itself becomes the root — which is what makes "why did this
     * message take four minutes to send" answerable at all. Linking the
     * job back to the web request that dispatched it needs the
     * traceparent carried in the job payload, which is a change to every
     * dispatch site rather than a listener, and is not done here.
     */
    private function traceQueue(Tracer $tracer): void
    {
        Event::listen(function (JobProcessing $event) use ($tracer): void {
            $tracer->startRoot(
                name: 'job '.$event->job->resolveName(),
                attributes: [
                    'orbital.queue' => $event->job->getQueue(),
                    'orbital.connection' => $event->connectionName,
                    'orbital.attempt' => $event->job->attempts(),
                ],
                kind: SpanKind::KIND_CONSUMER,
                owner: 'queue',
            );
        });

        Event::listen(function (JobProcessed $event) use ($tracer): void {
            $tracer->endRoot(['orbital.job_outcome' => 'processed'], owner: 'queue');
        });

        Event::listen(function (JobExceptionOccurred $event) use ($tracer): void {
            $tracer->endRoot(['orbital.job_outcome' => 'failed'], $event->exception, owner: 'queue');
        });
    }

    /**
     * Outbound HTTP calls, and the header that makes them part of the
     * same story.
     *
     * The traceparent is the load-bearing half. Orbital talks to Asterisk
     * ARI, LiveKit, the Python agent worker, Twilio and Anthropic, and
     * without propagation each of those is an opaque gap in the trace.
     * With it, a service on the other side that also traces continues the
     * same trace rather than starting its own.
     *
     * Only the host and the method are recorded, never the full URL: query
     * strings on these calls carry message SIDs, media URLs, and API keys.
     */
    private function traceOutboundHttp(Tracer $tracer): void
    {
        Http::globalRequestMiddleware(function ($request) use ($tracer) {
            $traceparent = $tracer->traceparent();

            return $traceparent
                ? $request->withHeader('traceparent', $traceparent)
                : $request;
        });
    }

    /**
     * Scheduled commands and artisan runs.
     *
     * Worth tracing for the same reason as jobs: `orbital:collect-metrics`
     * runs every minute and `orbital:generate-config` touches Asterisk,
     * and when one of those gets slow there is no request to look at.
     */
    private function traceConsole(Tracer $tracer): void
    {
        Event::listen(function (CommandStarting $event) use ($tracer): void {
            if ($event->command === null) {
                return;
            }

            $tracer->startRoot(
                name: 'artisan '.$event->command,
                attributes: ['orbital.command' => $event->command],
                kind: SpanKind::KIND_INTERNAL,
                owner: 'console',
            );
        });

        Event::listen(function (CommandFinished $event) use ($tracer): void {
            $tracer->endRoot(['orbital.exit_code' => $event->exitCode], owner: 'console');
        });
    }

    /**
     * The leading keyword of a statement, for the span name.
     *
     * Naming a span after the whole statement would put unbounded
     * cardinality into the backend's span-name index — the thing every
     * tracing backend is worst at. The statement itself is still on the
     * span as an attribute, where it is searchable but not indexed.
     */
    private function sqlVerb(string $sql): string
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\n\r") ?: '');

        return in_array($verb, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'BEGIN', 'COMMIT', 'ROLLBACK'], true)
            ? $verb
            : 'QUERY';
    }

    /**
     * Constant tags every event carries, so the project can be filtered
     * by build and channel without digging into each report.
     */
    private function tagScope(): void
    {
        \Sentry\configureScope(function (Scope $scope): void {
            $scope->setTag('orbital.channel', Release::channel());
            $scope->setTag('orbital.commit', Release::shortCommit() ?? 'unknown');
        });
    }
}
