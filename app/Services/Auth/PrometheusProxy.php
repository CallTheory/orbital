<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxies the Prometheus query UI through Laravel so it
 * inherits TLS from nginx-tls + session-based auth gating.
 * Prometheus has NO built-in authentication — anyone who can
 * reach it can query every metric. This proxy is the entire
 * trust boundary.
 */
class PrometheusProxy
{
    private const HOP_BY_HOP = [
        'host', 'connection', 'content-length', 'transfer-encoding',
        'upgrade', 'keep-alive', 'proxy-authenticate',
        'proxy-authorization', 'te', 'trailer',
    ];

    public function forward(Request $request, string $path = ''): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $internalUrl = rtrim((string) config('services.prometheus.internal_url'), '/');
        $target = $internalUrl.'/'.ltrim($path, '/');
        if ($request->getQueryString() !== null) {
            $target .= '?'.$request->getQueryString();
        }

        $headers = $this->prepareHeaders($request);

        try {
            $upstream = Http::withHeaders($headers)
                ->withOptions(['allow_redirects' => false, 'http_errors' => false])
                ->withBody(
                    $request->getContent(),
                    (string) ($request->header('Content-Type') ?? 'application/octet-stream'),
                )
                ->send($request->method(), $target);
        } catch (\Throwable $e) {
            Log::warning('Prometheus proxy forward failed', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            return response()->json(
                ['error' => 'prometheus_upstream_unreachable', 'message' => $e->getMessage()],
                502,
            );
        }

        return $this->buildResponse($upstream);
    }

    /**
     * @return array<string, string>
     */
    private function prepareHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, self::HOP_BY_HOP, true)) {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        $headers['Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Proto'] = $request->getScheme();
        $headers['X-Forwarded-For'] = $request->ip() ?? '127.0.0.1';

        return $headers;
    }

    private function buildResponse(\Illuminate\Http\Client\Response $upstream): Response
    {
        $prefix = '/admin/prometheus';
        $headers = [];
        foreach ($upstream->headers() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, self::HOP_BY_HOP, true) || $lower === 'content-length') {
                continue;
            }
            // Rewrite absolute-path Location redirects so the
            // browser stays inside the proxy prefix instead of
            // following to `/query` which doesn't exist in Laravel.
            if ($lower === 'location') {
                $values = array_map(function (string $url) use ($prefix): string {
                    if (str_starts_with($url, '/') && ! str_starts_with($url, $prefix)) {
                        return $prefix.$url;
                    }
                    return $url;
                }, $values);
            }
            $headers[$name] = implode(', ', $values);
        }

        return response($upstream->body(), $upstream->status(), $headers);
    }
}
