<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxies HTTP requests from Laravel's `/admin/grafana/*`
 * routes into the Grafana container, injecting the `X-WEBAUTH-USER`
 * header so Grafana's `[auth.proxy]` mode treats the Laravel user
 * as the authenticated identity.
 *
 * Trust chain:
 *   - Outer: the Laravel route this service is attached to sits
 *     behind `middleware('auth', 'tool:tooling.grafana')`, so the
 *     caller is guaranteed to be a signed-in user with the right
 *     permission before this proxy runs.
 *   - Inner: every forwarded request carries two headers Grafana
 *     trusts — `X-WEBAUTH-USER` (required, the identity claim) and
 *     `X-Orbital-Proxy-Token` (the shared-secret that a prod
 *     sidecar gatekeeper will validate ahead of Grafana itself).
 *     Dev relies on `GF_AUTH_PROXY_WHITELIST` matching the sail
 *     network; prod needs an nginx/Traefik shim that drops
 *     anything missing the token header before it reaches Grafana.
 *
 * Headers copied from the browser request: all except hop-by-hop
 * (`Connection`, `Content-Length`, `Transfer-Encoding`, `Host`) and
 * anything starting with `X-Webauth-` or `X-Orbital-Proxy-` — those
 * would let a malicious client spoof the identity. We strip them
 * from the inbound request before adding the ones we control.
 *
 * WebSocket upgrade requests (`/api/live/ws`) can't travel through
 * Laravel's HTTP client — streaming dashboards don't work in this
 * first pass. We return a 501 with a diagnostic body so the Grafana
 * Live panel can render a visible error instead of hanging.
 */
class GrafanaProxy
{
    /** Hop-by-hop and structural headers we never forward. */
    private const HOP_BY_HOP = [
        'host',
        'connection',
        'content-length',
        'transfer-encoding',
        'upgrade',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
    ];

    public function forward(Request $request, string $path = ''): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if ($this->isWebSocketPath($path)) {
            return response()->json(
                [
                    'error' => 'grafana_live_unsupported',
                    'message' => 'Grafana Live WebSocket streaming is not yet supported by the Laravel reverse proxy. '
                        .'Non-streaming dashboards work normally.',
                ],
                501,
            );
        }

        // Grafana is configured with `serve_from_sub_path=true` and
        // `root_url=.../admin/grafana/`, which means Grafana EXPECTS
        // every request to come in under the `/admin/grafana/`
        // prefix and strips it internally. If we forward just the
        // `{path}` portion (e.g. `/login`), Grafana sees a bare
        // `/login` and 302s to `/admin/grafana/login` as the
        // "correct" URL — the browser follows, Laravel forwards
        // `/login` again, and the loop never ends. Fix: forward
        // the full original request path including `admin/grafana/`
        // so Grafana sees exactly what the browser asked for.
        $internalUrl = rtrim((string) config('services.grafana.internal_url'), '/');
        $target = $internalUrl.'/'.ltrim($request->path(), '/');
        if ($request->getQueryString() !== null) {
            $target .= '?'.$request->getQueryString();
        }

        $headers = $this->prepareHeaders($request, $user->email, $user->name);

        try {
            $upstream = Http::withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => false,
                    'http_errors' => false,
                ])
                ->withBody(
                    $request->getContent(),
                    (string) ($request->header('Content-Type') ?? 'application/octet-stream'),
                )
                ->send($request->method(), $target);
        } catch (\Throwable $e) {
            Log::warning('Grafana proxy forward failed', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            return response()->json(
                ['error' => 'grafana_upstream_unreachable', 'message' => $e->getMessage()],
                502,
            );
        }

        return $this->buildResponse($upstream);
    }

    /**
     * @return array<string, string>
     */
    private function prepareHeaders(Request $request, string $email, string $name): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name_ => $values) {
            $lower = strtolower($name_);
            if (in_array($lower, self::HOP_BY_HOP, true)) {
                continue;
            }
            // Drop any inbound X-WEBAUTH-* or X-Orbital-Proxy-* to
            // prevent the browser from spoofing an identity upstream.
            if (str_starts_with($lower, 'x-webauth-') || str_starts_with($lower, 'x-orbital-proxy-')) {
                continue;
            }
            $headers[$name_] = implode(', ', $values);
        }

        // Preserve the user's original Host header so Grafana's
        // `root_url` template (`%(protocol)s://%(domain)s/admin/grafana/`)
        // expands using the hostname the BROWSER knows about,
        // not `grafana:3000` which is the internal service name
        // only reachable from the sail network. Without this,
        // Grafana generates Location redirects pointing at
        // grafana:3000, the browser tries to follow, DNS fails,
        // and the browser reports "ERR_TOO_MANY_REDIRECTS".
        $headers['Host'] = $request->getHttpHost();

        // Tell Grafana the forwarded request is for a specific
        // host + protocol so any code path reading these headers
        // (trust proxy, asset URLs) also resolves correctly.
        $headers['X-Forwarded-Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Proto'] = $request->getScheme();
        $headers['X-Forwarded-For'] = $request->ip() ?? '127.0.0.1';

        // Identity claim for Grafana's auth.proxy mode.
        $headers['X-WEBAUTH-USER'] = $email;
        $headers['X-WEBAUTH-EMAIL'] = $email;
        $headers['X-WEBAUTH-NAME'] = $name;

        // Shared-secret header for the prod trust-chain gatekeeper.
        $trustToken = (string) config('services.grafana.proxy_trust_token');
        if ($trustToken !== '') {
            $headers['X-Orbital-Proxy-Token'] = $trustToken;
        }

        return $headers;
    }

    private function buildResponse(\Illuminate\Http\Client\Response $upstream): Response
    {
        $headers = [];
        foreach ($upstream->headers() as $name_ => $values) {
            $lower = strtolower($name_);
            if (in_array($lower, self::HOP_BY_HOP, true)) {
                continue;
            }
            // Drop Content-Length too — the framework will recompute
            // it from the body and a stale value would break the
            // browser's parse.
            if ($lower === 'content-length') {
                continue;
            }
            $headers[$name_] = implode(', ', $values);
        }

        return response($upstream->body(), $upstream->status(), $headers);
    }

    private function isWebSocketPath(string $path): bool
    {
        return str_contains($path, 'api/live/ws');
    }
}
