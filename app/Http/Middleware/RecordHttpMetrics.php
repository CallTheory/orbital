<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Metrics\HttpMetricsStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records request count + duration for every HTTP request this instance
 * serves, into the Valkey-backed HttpMetricsStore.
 *
 * Runs in terminate() so the measurement work happens after the response
 * has already been flushed to the client — a slow Valkey write shows up
 * in the container's own latency, never in the user's.
 *
 * Duration is measured from LARAVEL_START where available (set in
 * public/index.php before the framework boots) so the number includes
 * bootstrap, not just the routed portion. That's the honest figure: a
 * slow boot is slow for the user regardless of which layer owns it.
 */
class RecordHttpMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('metrics.enabled', true) || ! config('metrics.http.enabled', true)) {
            return;
        }

        // Don't measure the scrape itself. Prometheus hits /metrics every
        // 15s forever, which would otherwise dominate the request counts
        // and make the graphs describe the monitoring rather than the
        // product.
        if ($request->is('metrics', 'up')) {
            return;
        }

        $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        $duration = is_numeric($start) ? max(0.0, microtime(true) - (float) $start) : 0.0;

        HttpMetricsStore::forCurrentInstance()->record(
            surface: self::surfaceFor($request),
            method: $request->getMethod(),
            status: $response->getStatusCode(),
            durationSeconds: $duration,
        );
    }

    /**
     * Coarse bucket for which part of the product served the request.
     * Kept to a fixed, small set on purpose — see the cardinality note
     * in config/metrics.php.
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
