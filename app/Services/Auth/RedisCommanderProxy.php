<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxies Redis Commander through Laravel, injecting HTTP
 * Basic Auth on every forwarded request so the browser never sees
 * a login prompt. Replaces the earlier JWT SSO redirect approach
 * which broke under HTTPS (mixed-content redirect from HTTPS page
 * to HTTP Commander port).
 *
 * Same pattern as IcecastProxy — Commander has HTTP_USER +
 * HTTP_PASSWORD configured, we inject them server-side.
 */
class RedisCommanderProxy
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

        $internalUrl = rtrim((string) config('services.redis_commander.internal_url'), '/');
        $target = $internalUrl.'/'.ltrim($path, '/');
        if ($request->getQueryString() !== null) {
            $target .= '?'.$request->getQueryString();
        }

        $headers = $this->prepareHeaders($request);

        try {
            // No Basic auth injection — Commander's own auth is
            // disabled (no HTTP_USER/HTTP_PASSWORD in its env).
            // All auth is handled by the Laravel route middleware.
            $upstream = Http::withHeaders($headers)
                ->withOptions(['allow_redirects' => false, 'http_errors' => false])
                ->withBody(
                    $request->getContent(),
                    (string) ($request->header('Content-Type') ?? 'application/octet-stream'),
                )
                ->send($request->method(), $target);
        } catch (\Throwable $e) {
            Log::warning('Redis Commander proxy forward failed', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            return response()->json(
                ['error' => 'redis_commander_upstream_unreachable', 'message' => $e->getMessage()],
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
            if (in_array($lower, self::HOP_BY_HOP, true) || $lower === 'authorization') {
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
        $headers = [];
        foreach ($upstream->headers() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, self::HOP_BY_HOP, true) || $lower === 'content-length') {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        $body = $upstream->body();
        $contentType = (string) ($upstream->header('Content-Type') ?? '');

        // Redis Commander's HTML uses relative paths for all assets
        // (scripts/browserify.js, css/default.css, etc.). Without a
        // <base> tag the browser resolves them against the current
        // URL which may not have a trailing slash (Laravel strips
        // them during route matching). Injecting <base> makes
        // relative resolution deterministic.
        if (str_contains($contentType, 'text/html') && stripos($body, '<head>') !== false) {
            $body = preg_replace(
                '/<head>/i',
                '<head><base href="/admin/redis-commander/">',
                $body,
                1,
            ) ?? $body;
        }

        return response($body, $upstream->status(), $headers);
    }
}
