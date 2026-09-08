<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Metrics\Exposition;
use App\Services\Metrics\HttpMetricsStore;
use App\Support\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /metrics — Prometheus scrape endpoint for THIS app instance.
 *
 * Scope is deliberately narrow: per-instance HTTP traffic and build
 * identity. Deployment-wide numbers (per-client volume, queue depth,
 * telephony state) are pushed once by `orbital:collect-metrics` instead
 * of being served by every replica, because N replicas each reporting
 * the same global total is a counting bug waiting to happen. The full
 * reasoning is in config/metrics.php.
 *
 * Reachability: the app container's HTTP port is not host-bound, so on a
 * default deployment only nginx-tls and Prometheus can reach this. Set
 * METRICS_TOKEN when that isn't true.
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! config('metrics.enabled', true)) {
            return response('metrics disabled', 404)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $token = config('metrics.token');

        if (is_string($token) && $token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            return response('unauthorized', 401)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $exposition = new Exposition;

        $exposition->gauge(
            'orbital_build_info',
            1,
            [
                'version' => Release::version(),
                'commit' => Release::shortCommit() ?? 'unknown',
                'channel' => Release::channel(),
                'license' => Release::licenseSpdx(),
                'instance_name' => (string) config('metrics.instance'),
            ],
            'Orbital build identity for this instance. Always 1; read the labels.',
        );

        HttpMetricsStore::forCurrentInstance()->addTo($exposition);

        return response($exposition->render(), 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }
}
