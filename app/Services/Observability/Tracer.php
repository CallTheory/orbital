<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Support\Release;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Throwable;

/**
 * Owns the OpenTelemetry pipeline and the current request's root span.
 *
 * A singleton because the root span has to survive from the middleware's
 * handle() to its terminate(), and Laravel resolves middleware from the
 * container twice — the object that opened the span is not the object
 * that closes it. Hanging the span here rather than on the middleware is
 * what makes that work.
 *
 * Three rules run through everything below:
 *
 *   1. Off costs nothing. With tracing disabled no provider is built, no
 *      transport is constructed, and every method returns immediately.
 *   2. Tracing never breaks the request. A bad endpoint, an unreachable
 *      collector, a malformed credential — all of it is swallowed, once,
 *      and then the whole pipeline stands down for the rest of the
 *      process rather than throwing on every span.
 *   3. Spans carry IDs, never content. Nothing here ever puts a caller's
 *      name, a phone number, a message body, or a SQL bound value on a
 *      span. See config/observability.php.
 */
class Tracer
{
    private ?TracerProvider $provider = null;

    /**
     * Set once construction has failed. The exporter is not retried
     * afterwards: a misconfigured endpoint would otherwise mean a failed
     * connection attempt on every span of every request, which turns a
     * cosmetic problem into a latency one.
     */
    private bool $unavailable = false;

    /**
     * Overrides the OTLP exporter. Exists so tests can assert on what
     * would have been shipped without standing up a collector — the
     * transport bypasses Laravel's HTTP client (it resolves a PSR-18
     * client through php-http/discovery), so Http::fake() cannot see it.
     */
    private ?SpanExporterInterface $exporterOverride = null;

    private ?SpanInterface $root = null;

    private ?ScopeInterface $rootScope = null;

    /**
     * Which seam opened the current root, and how deeply that seam has
     * re-entered. Together these make endRoot() safe to call from an
     * event listener that may or may not have been the one to open
     * anything — see startRoot().
     */
    private ?string $rootOwner = null;

    private int $rootDepth = 0;

    private int $dbSpans = 0;

    public function useExporter(?SpanExporterInterface $exporter): void
    {
        $this->exporterOverride = $exporter;
        $this->provider = null;
        $this->unavailable = false;
    }

    public function enabled(): bool
    {
        return ! $this->unavailable
            && (bool) config('observability.tracing.enabled')
            && trim((string) config('observability.tracing.endpoint', '')) !== '';
    }

    /**
     * Begin the span everything else in this request hangs off.
     *
     * `$traceparent` is the inbound W3C header. Honouring it is what
     * makes a trace cross a process boundary: the Python agent worker
     * calling the Orbital API, or a queued job continuing the web
     * request that dispatched it, land in the caller's trace instead of
     * starting an orphan.
     *
     * `$owner` names the seam asking, and it is what makes endRoot()
     * safe to call blindly from an event listener. Two cases it handles:
     *
     *   - A job on the `sync` driver is processed INSIDE the web request
     *     that dispatched it, so JobProcessing fires with an HTTP root
     *     already open. A queue listener that closed it would truncate
     *     the request's trace and orphan every span after the dispatch.
     *     Different owner, so the queue seam neither opens nor closes.
     *   - Artisan::call() inside a command, or a job dispatched inside a
     *     job, re-enters the SAME seam. That increments a depth counter
     *     instead, so the inner run nests in the outer trace and only the
     *     outermost end actually closes it.
     *
     * Either way the nested work still lands in the open trace, which is
     * the picture you wanted.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function startRoot(
        string $name,
        array $attributes = [],
        int $kind = SpanKind::KIND_SERVER,
        ?string $traceparent = null,
        string $owner = 'root',
    ): bool {
        if (! $this->enabled()) {
            return false;
        }

        if ($this->root !== null) {
            if ($this->rootOwner === $owner) {
                $this->rootDepth++;
            }

            return false;
        }

        $this->guard(function () use ($name, $attributes, $kind, $traceparent): void {
            $parent = $traceparent
                ? TraceContextPropagator::getInstance()->extract(['traceparent' => $traceparent])
                : Context::getCurrent();

            $span = $this->tracer()
                ->spanBuilder($name)
                ->setSpanKind($kind)
                ->setParent($parent)
                ->startSpan();

            $span->setAttributes($attributes);

            $this->root = $span;
            $this->rootScope = $span->activate();
        });

        if ($this->root === null) {
            return false;
        }

        $this->rootOwner = $owner;
        $this->rootDepth = 1;

        return true;
    }

    /**
     * Close the root, if this seam is the one that opened it.
     *
     * Safe to call unconditionally: a mismatched owner or an unbalanced
     * nesting level is a no-op rather than a truncated trace.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function endRoot(array $attributes = [], ?Throwable $error = null, string $owner = 'root'): void
    {
        if ($this->root === null || $this->rootOwner !== $owner) {
            return;
        }

        if (--$this->rootDepth > 0) {
            return;
        }

        $this->guard(function () use ($attributes, $error): void {
            $this->root->setAttributes($attributes);

            if ($error) {
                $this->root->recordException($error);
                $this->root->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
            }

            $this->rootScope?->detach();
            $this->root->end();
        });

        $this->root = null;
        $this->rootScope = null;
        $this->rootOwner = null;
        $this->rootDepth = 0;
        $this->dbSpans = 0;

        $this->flush();
    }

    /**
     * A completed child span, recorded after the fact.
     *
     * Most of what Orbital wants to trace is reported by an event that
     * fires once the work is already done and carries its own duration —
     * a query, a finished job, an HTTP response. Backdating the start
     * time is more honest than opening a span at the moment we hear
     * about it and pretending it took no time.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(
        string $name,
        float $durationSeconds,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
    ): void {
        if (! $this->enabled() || $this->root === null) {
            return;
        }

        $this->guard(function () use ($name, $durationSeconds, $attributes, $kind): void {
            $endNanos = (int) (microtime(true) * 1_000_000_000);
            $startNanos = $endNanos - (int) max(0.0, $durationSeconds * 1_000_000_000);

            $span = $this->tracer()
                ->spanBuilder($name)
                ->setSpanKind($kind)
                ->setStartTimestamp($startNanos)
                ->startSpan();

            $span->setAttributes($attributes);
            $span->end($endNanos);
        });
    }

    /**
     * Have we already recorded as many database spans as this trace is
     * allowed? See `max_db_spans` in config/observability.php.
     */
    public function acceptDbSpan(): bool
    {
        $max = (int) config('observability.tracing.max_db_spans', 100);

        if ($max > 0 && $this->dbSpans >= $max) {
            return false;
        }

        $this->dbSpans++;

        return true;
    }

    /**
     * The current context as a W3C traceparent, for injecting into an
     * outbound request. Null when there is nothing to propagate.
     */
    public function traceparent(): ?string
    {
        if (! $this->enabled() || $this->root === null) {
            return null;
        }

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);

        return $carrier['traceparent'] ?? null;
    }

    public function hasActiveTrace(): bool
    {
        return $this->root !== null;
    }

    /**
     * Push buffered spans at the exporter.
     *
     * Called after the response has been flushed to the client, so the
     * export happens on the container's time rather than the user's.
     */
    public function flush(): void
    {
        if ($this->provider === null) {
            return;
        }

        $this->guard(function (): void {
            $this->provider->forceFlush();
        });
    }

    public function shutdown(): void
    {
        if ($this->provider === null) {
            return;
        }

        $this->guard(function (): void {
            $this->provider->shutdown();
        });

        $this->provider = null;
    }

    private function tracer(): TracerInterface
    {
        return $this->provider()->getTracer('orbital');
    }

    private function provider(): TracerProvider
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $exporter = $this->exporterOverride ?? new SpanExporter(
            (new OtlpHttpTransportFactory)->create(
                (string) config('observability.tracing.endpoint'),
                $this->contentType(),
                $this->headers(),
                null,
                (float) config('observability.tracing.timeout', 5.0),
            ),
        );

        return $this->provider = TracerProvider::builder()
            ->addSpanProcessor(new BatchSpanProcessor($exporter, ClockFactory::getDefault()))
            ->setResource($this->resource())
            ->setSampler($this->sampler())
            ->build();
    }

    /**
     * Who is reporting. `service.instance.id` reuses the metrics
     * instance name deliberately, so a replica's traces and its metrics
     * agree on what to call it — otherwise correlating a latency spike
     * with the pod that caused it means guessing.
     */
    private function resource(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => (string) config('observability.tracing.service_name', 'orbital'),
                ResourceAttributes::SERVICE_VERSION => Release::version(),
                ResourceAttributes::SERVICE_INSTANCE_ID => (string) config('metrics.instance'),
                ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => (string) app()->environment(),
            ])),
        );
    }

    /**
     * Parent-based, so a decision taken once at the edge of a request is
     * respected by everything downstream. Sampling each span
     * independently would produce traces with holes in them, which are
     * worse than no trace: a missing span reads as work that did not
     * happen.
     */
    private function sampler(): ParentBased
    {
        $ratio = (float) config('observability.tracing.sample_ratio', 0.05);

        if ($ratio <= 0.0) {
            return new ParentBased(new AlwaysOffSampler);
        }

        return new ParentBased(new TraceIdRatioBasedSampler(min(1.0, $ratio)));
    }

    private function contentType(): string
    {
        return config('observability.tracing.protocol') === 'http/json'
            ? 'application/json'
            : 'application/x-protobuf';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $mode = (string) config('observability.tracing.auth.mode', 'none');
        $secret = (string) config('observability.tracing.auth.secret', '');

        if ($secret === '') {
            return [];
        }

        return match ($mode) {
            // Grafana Cloud: username is the numeric instance ID.
            'basic' => ['Authorization' => 'Basic '.base64_encode(
                config('observability.tracing.auth.username').':'.$secret
            )],
            'bearer' => ['Authorization' => 'Bearer '.$secret],
            default => [],
        };
    }

    /**
     * Run tracing work, or give up on tracing entirely.
     *
     * Rule 2 from the class docblock lives here. Nothing about observing
     * a request may change its outcome, so a failure marks the pipeline
     * unavailable for the rest of the process and is logged once — the
     * alternative, logging per span, replaces a broken exporter with a
     * flooded log.
     */
    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $this->unavailable = true;
            $this->root = null;
            $this->rootScope = null;
            $this->rootOwner = null;
            $this->rootDepth = 0;

            // error_log rather than Log::error: the logging stack may
            // itself be mid-failure, and this must never be the thing
            // that throws.
            error_log('orbital tracing disabled for this process: '.$e->getMessage());
        }
    }
}
