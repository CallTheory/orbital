<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Observability\Tracer;
use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the span every other span in a request hangs off, and closes it
 * once the response has gone out.
 *
 * Sits beside RecordHttpMetrics and shares its conventions: the same
 * coarse surface label, the same exemption for /metrics and /up, and the
 * same principle that the expensive part happens in terminate() so
 * observing a request never shows up in the user's latency.
 *
 * Where it differs is that a span has to span the request, so state
 * cannot live on the middleware — Laravel resolves middleware from the
 * container twice, and the instance that runs terminate() is not the one
 * that ran handle(). The span lives on the Tracer singleton instead.
 *
 * The span NAME is the route pattern, not the URL. `operator/message-thread/{threadId}`
 * groups; `operator/message-thread/8412` produces one trace name per
 * conversation and makes the backend's own aggregation useless. The full
 * URL is left off entirely: tenant-scoped paths carry ids, and a trace
 * backend is not where that belongs.
 */
class TraceRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $tracer = app(Tracer::class);

        if (! $tracer->enabled() || $this->shouldSkip($request)) {
            return $next($request);
        }

        $tracer->startRoot(
            name: $this->spanName($request),
            attributes: [
                TraceAttributes::HTTP_REQUEST_METHOD => $request->getMethod(),
                TraceAttributes::URL_PATH => '/'.ltrim($request->path(), '/'),
                TraceAttributes::URL_SCHEME => $request->getScheme(),
                'orbital.surface' => self::surfaceFor($request),
            ],
            kind: SpanKind::KIND_SERVER,

            // Continues a trace started elsewhere — the Python agent
            // worker calling our API, a load balancer, or another
            // Orbital service. Without this every inbound request starts
            // a fresh trace and the cross-process picture is lost.
            traceparent: $request->header('traceparent'),

            // Only this middleware closes what this middleware opened.
            // A job on the `sync` driver runs inside the request and
            // would otherwise be able to end the request's own span.
            owner: 'http',
        );

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $tracer = app(Tracer::class);

        if (! $tracer->hasActiveTrace()) {
            return;
        }

        $attributes = [
            TraceAttributes::HTTP_RESPONSE_STATUS_CODE => $response->getStatusCode(),
        ];

        // Who it was, not which person: an id and a client are enough to
        // reproduce a problem and to tell whether it affects one tenant
        // or all of them. A name or an email is neither needed nor ours
        // to export.
        if ($user = $request->user()) {
            $attributes['orbital.user_id'] = (string) $user->getAuthIdentifier();
            $attributes['orbital.team_id'] = (string) ($user->current_team_id ?? '');
        }

        $tracer->endRoot($attributes, owner: 'http');
    }

    /**
     * Prometheus scrapes /metrics every 15 seconds forever and the
     * health check hits /up constantly. Tracing them would describe the
     * monitoring rather than the product.
     */
    private function shouldSkip(Request $request): bool
    {
        return $request->is('metrics', 'up');
    }

    private function spanName(Request $request): string
    {
        $route = $request->route();
        $pattern = $route?->uri();

        return $request->getMethod().' '.($pattern ? '/'.ltrim($pattern, '/') : '/'.ltrim($request->path(), '/'));
    }

    /**
     * Same buckets as RecordHttpMetrics, on purpose — an operator moving
     * between a dashboard and a trace should not have to translate.
     */
    private static function surfaceFor(Request $request): string
    {
        $path = $request->path();

        return match (true) {
            str_starts_with($path, 'admin') => 'admin',
            str_starts_with($path, 'operator') => 'operator',
            str_starts_with($path, 'portal') => 'portal',
            str_starts_with($path, 'api') => 'api',
            str_starts_with($path, 'livewire') => 'livewire',
            str_starts_with($path, 'chat') => 'chat',
            str_starts_with($path, 'oauth') => 'oauth',
            default => 'web',
        };
    }
}
