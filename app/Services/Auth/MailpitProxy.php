<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxies the Mailpit web UI through Laravel so it
 * inherits TLS from nginx-tls + session-based auth gating.
 * Mailpit has no built-in authentication — anyone who can
 * reach port 8025 can read every captured email. This proxy
 * is the entire trust boundary.
 *
 * Mailpit runs in every environment (dev + prod) as a debug
 * mail trap. In production, MAIL_HOST points at the real SMTP
 * relay and Mailpit sits idle — available if the operator
 * temporarily flips MAIL_HOST=mailpit to capture and inspect
 * outbound mail for debugging.
 */
class MailpitProxy
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

        // Mailpit runs with --webroot=/admin/mailpit, so it expects
        // the full path prefix in every request. Forward the
        // complete request path (which includes /admin/mailpit/)
        // rather than just the {path} route parameter.
        //
        // Laravel strips trailing slashes during route matching,
        // so $request->path() returns `admin/mailpit` even when
        // the browser hit `/admin/mailpit/`. Since Mailpit 302s
        // bare `/admin/mailpit` → `/admin/mailpit/`, we'd loop
        // forever. Fix: append `/` when forwarding the root path.
        $internalUrl = rtrim((string) config('services.mailpit.internal_url'), '/');
        $requestPath = $request->path();
        if ($path === '' || $path === '/') {
            $requestPath = rtrim($requestPath, '/').'/';
        }
        $target = $internalUrl.'/'.ltrim($requestPath, '/');
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
            Log::warning('Mailpit proxy forward failed', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            return response()->json(
                ['error' => 'mailpit_upstream_unreachable', 'message' => $e->getMessage()],
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
        $headers = [];
        foreach ($upstream->headers() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, self::HOP_BY_HOP, true) || $lower === 'content-length') {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        // No URL rewriting — Mailpit's --webroot flag makes it
        // serve all assets and API calls under /admin/mailpit/
        // natively, so paths already match the proxy route prefix.

        return response($upstream->body(), $upstream->status(), $headers);
    }
}
