<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxies the SeaweedFS filer web UI and master admin
 * through Laravel so signed-in super-admins can reach them
 * without exposing the raw container ports. SeaweedFS's
 * community build ships BOTH endpoints with no authentication
 * (and both accept write operations), so loopback binding on
 * the host port + this proxy are together the only thing
 * standing between "logged-in admin" and "wide-open filesystem".
 *
 * Unlike the Icecast proxy, there's no Basic-Auth header to
 * inject upstream — SeaweedFS doesn't check one. The trust
 * boundary is entirely at the Laravel route layer:
 *   `middleware('auth', 'tool:tooling.seaweedfs')` on the
 *   `/admin/seaweedfs/{filer|master}/{path?}` routes.
 *
 * Two upstream targets supported:
 *   filer  → http://seaweedfs:8888  (file browser web UI)
 *   master → http://seaweedfs:9333  (cluster status + admin)
 *
 * Pattern follows `IcecastProxy`: Laravel HTTP client forwards
 * the request, rewrites absolute paths in HTML/CSS responses
 * to stay inside the proxy prefix, and passes binaries through
 * untouched. Host header is set to the user's original host so
 * any relative URLs SeaweedFS generates resolve back through
 * our proxy instead of the internal `seaweedfs:*` hostnames.
 */
class SeaweedFsProxy
{
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

    public function forwardFiler(Request $request, string $path = ''): Response
    {
        return $this->forward(
            $request,
            $path,
            (string) config('services.seaweedfs.filer_url'),
            'filer',
        );
    }

    public function forwardMaster(Request $request, string $path = ''): Response
    {
        return $this->forward(
            $request,
            $path,
            (string) config('services.seaweedfs.master_url'),
            'master',
        );
    }

    private function forward(Request $request, string $path, string $internalUrl, string $kind): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $internalUrl = rtrim($internalUrl, '/');
        $target = $internalUrl.'/'.ltrim($path, '/');
        if ($request->getQueryString() !== null) {
            $target .= '?'.$request->getQueryString();
        }

        $headers = $this->prepareHeaders($request);

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
            Log::warning('SeaweedFS proxy forward failed', [
                'kind' => $kind,
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            return response()->json(
                ['error' => 'seaweedfs_upstream_unreachable', 'message' => $e->getMessage()],
                502,
            );
        }

        return $this->buildResponse($upstream, $kind);
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

        // Preserve the browser's Host so relative paths SeaweedFS
        // generates (its HTML UI is minimal but still has some
        // href="/..." entries) resolve back through the proxy
        // rather than the internal `seaweedfs:8888` hostname.
        $headers['Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Proto'] = $request->getScheme();
        $headers['X-Forwarded-For'] = $request->ip() ?? '127.0.0.1';

        return $headers;
    }

    private function buildResponse(\Illuminate\Http\Client\Response $upstream, string $kind): Response
    {
        $headers = [];
        foreach ($upstream->headers() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, self::HOP_BY_HOP, true) || $lower === 'content-length') {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        $body = $this->rewriteBody(
            $upstream->body(),
            (string) ($upstream->header('Content-Type') ?? ''),
            $kind,
        );

        return response($body, $upstream->status(), $headers);
    }

    /**
     * Rewrite absolute-path URLs in HTML/CSS/XML responses so
     * links stay inside the `/admin/seaweedfs/{kind}/` prefix.
     * Binaries pass through unchanged.
     */
    private function rewriteBody(string $body, string $contentType, string $kind): string
    {
        $isHtml = str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml')
            || str_contains($contentType, 'text/xml')
            || str_contains($contentType, 'application/xml');
        $isCss = str_contains($contentType, 'text/css');

        if (! $isHtml && ! $isCss) {
            return $body;
        }

        $prefix = '/admin/seaweedfs/'.$kind;
        return strtr($body, [
            'href="/' => 'href="'.$prefix.'/',
            "href='/" => "href='".$prefix.'/',
            'src="/' => 'src="'.$prefix.'/',
            "src='/" => "src='".$prefix.'/',
            'action="/' => 'action="'.$prefix.'/',
            "action='/" => "action='".$prefix.'/',
            'url("/' => 'url("'.$prefix.'/',
            "url('/" => "url('".$prefix.'/',
            'url(/' => 'url('.$prefix.'/',
        ]);
    }
}
