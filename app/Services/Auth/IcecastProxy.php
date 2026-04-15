<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxies the Icecast admin UI through Laravel so clicking
 * the nav link drops a signed-in super-admin straight into the
 * admin page without the browser prompting for HTTP Basic creds.
 *
 * Trust chain:
 *   - Outer: the Laravel route this runs under is gated by
 *     `middleware('auth', 'tool:tooling.icecast')`, so only a
 *     permitted user reaches this code.
 *   - Inner: Laravel adds `Authorization: Basic <...>` with the
 *     `ICECAST_ADMIN_USER` / `ICECAST_ADMIN_PASSWORD` env values
 *     on the forwarded request. Icecast's auth is a per-request
 *     HTTP Basic check; no session cookie, no CSRF, no redirects,
 *     so the proxy is simpler than Grafana's.
 *
 * Host-header handling is the same as the Grafana proxy: forward
 * the user's original host so any relative URLs Icecast generates
 * resolve back through `/admin/icecast/...` rather than the
 * internal `icecast:8000` hostname.
 *
 * Streams (mountpoint URLs like `/stream.ogg`) would push audio
 * through PHP-FPM if they hit this proxy — not what we want. Only
 * the `/admin*` and `/status*` paths are meant to travel through
 * here. The route registration in `routes/web.php` scopes this
 * proxy to the admin UI only.
 */
class IcecastProxy
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

    public function forward(Request $request, string $path = ''): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        // Empty / bare-'admin' paths map to Icecast's /admin/
        // directory listing. We can't redirect to a trailing-
        // slash URL because Laravel strips trailing slashes
        // during route matching, so `/admin/icecast/admin/`
        // captures `$path='admin'` and we'd loop forever. Instead
        // forward directly, and `baseHrefFor()` below uses the
        // upstream directory (not the browser URL) to compute
        // the correct relative-link base.
        if ($path === '' || $path === 'admin') {
            $upstreamPath = 'admin/';
        } else {
            $upstreamPath = $path;
        }

        $internalUrl = rtrim((string) config('services.icecast.internal_url'), '/');
        $target = $internalUrl.'/'.ltrim($upstreamPath, '/');
        if ($request->getQueryString() !== null) {
            $target .= '?'.$request->getQueryString();
        }

        $headers = $this->prepareHeaders($request);

        try {
            $upstream = Http::withHeaders($headers)
                ->withBasicAuth(
                    (string) config('services.icecast.admin_user'),
                    (string) config('services.icecast.admin_password'),
                )
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
            Log::warning('Icecast proxy forward failed', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            return response()->json(
                ['error' => 'icecast_upstream_unreachable', 'message' => $e->getMessage()],
                502,
            );
        }

        return $this->buildResponse($upstream, $upstreamPath);
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
            // Strip any inbound Authorization — we're going to
            // replace it with our server-side Basic credentials,
            // and letting a client-supplied header through would
            // let an attacker substitute their own.
            if ($lower === 'authorization') {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        // Preserve the browser's Host so Icecast's relative
        // `Location` redirects (e.g. after deleting a mountpoint)
        // resolve back through our proxy instead of targeting the
        // internal `icecast:8000` hostname.
        $headers['Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Proto'] = $request->getScheme();
        $headers['X-Forwarded-For'] = $request->ip() ?? '127.0.0.1';

        return $headers;
    }

    private function buildResponse(\Illuminate\Http\Client\Response $upstream, string $upstreamPath): Response
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
            $upstreamPath,
        );

        return response($body, $upstream->status(), $headers);
    }

    /**
     * Rewrite absolute-path URLs in Icecast's HTML + XSL responses
     * so stylesheets, scripts, images, and form actions route
     * back through our proxy prefix instead of hitting the bare
     * path on our own app (which 404s).
     *
     * Icecast's admin pages contain markup like:
     *   <link rel="stylesheet" href="/admin/style.css">
     *   <img src="/admin/images/icecast.png">
     *   <a href="/admin/listmounts">...</a>
     *
     * Without rewriting, the browser requests these at
     *   http://orbital.test/admin/style.css
     * which is a Laravel 404 because only `/admin/icecast/*` is
     * proxied. We prepend `/admin/icecast` to every absolute-path
     * href/src/action so they become
     *   /admin/icecast/admin/style.css
     * and travel back through this proxy.
     *
     * Only applied to text/html and text/xml responses — CSS,
     * images, and stream payloads are passed through unchanged
     * so we don't corrupt binaries.
     */
    private function rewriteBody(string $body, string $contentType, string $upstreamPath): string
    {
        $isHtml = str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml')
            || str_contains($contentType, 'text/xml')
            || str_contains($contentType, 'application/xml')
            || str_contains($contentType, 'text/xsl');
        $isCss = str_contains($contentType, 'text/css');

        // Passthrough for non-textual content (images, binaries,
        // streams) so we don't corrupt byte payloads.
        if (! $isHtml && ! $isCss) {
            return $body;
        }

        $prefix = '/admin/icecast';
        $rewritten = strtr($body, [
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

        if ($isHtml) {
            // Strip the legacy "rounded corner" decoration images.
            // Icecast's XSL templates still emit `<img class="corner"
            // style="display: none">` entries pointing at files like
            // `corner_topleft.jpg` that don't ship in the moul/icecast
            // image. Removing the tags keeps the network tab clean.
            $rewritten = preg_replace(
                '/<img\s[^>]*class=("|\')corner\1[^>]*>/i',
                '',
                $rewritten,
            ) ?? $rewritten;
        }

        if ($isCss) {
            // style.css references background images for the same
            // legacy rounded-corner decoration that doesn't exist
            // in the moul/icecast container — `/corner_topright.jpg`
            // and `/corner_bottomright.jpg`. After the `url(/`
            // rewrite above these become `/admin/icecast/corner_*.jpg`
            // which the proxy forwards to Icecast and gets back a
            // 404. Strip the whole background rule so the browser
            // never requests them.
            $rewritten = preg_replace(
                '/\s*background:\s*url\([^)]*corner_[^)]*\)[^;]*;?/i',
                '',
                $rewritten,
            ) ?? $rewritten;
        }

        // Inject a <base> tag so relative URLs in Icecast's
        // admin + public pages (`href="stats.xsl"`, `href="style.css"`)
        // resolve against the right proxy prefix regardless of
        // whether the browser's current URL has a trailing slash.
        //
        // Using the UPSTREAM path here (what we forwarded to
        // Icecast) rather than the browser's URL path fixes two
        // things at once:
        //   1. Laravel strips trailing slashes during route
        //      matching, so the route-param $path never has one
        //      — computing base from $request->path() mis-bases
        //      directory listings against the PARENT dir.
        //   2. The upstream path is always canonically-shaped
        //      (we normalized '' and 'admin' to 'admin/' above),
        //      so `dirname` + a `/` gives the right base.
        //
        // Upstream → base-href mapping:
        //   'admin/'              → /admin/icecast/admin/
        //   'admin/stats.xsl'     → /admin/icecast/admin/
        //   'status.xsl'          → /admin/icecast/
        if ($isHtml) {
            $baseHref = '/admin/icecast/'.$this->upstreamDir($upstreamPath);
            $baseTag = '<base href="'.$baseHref.'">';

            if (stripos($rewritten, '<head>') !== false) {
                $rewritten = preg_replace(
                    '/<head>/i',
                    '<head>'.$baseTag,
                    $rewritten,
                    1,
                );
            } elseif (stripos($rewritten, '<html') !== false) {
                // Icecast's XSL output sometimes omits <head>;
                // inject a minimal head directly after the <html>
                // opening tag.
                $rewritten = preg_replace(
                    '/(<html[^>]*>)/i',
                    '$1<head>'.$baseTag.'</head>',
                    $rewritten,
                    1,
                );
            }
        }

        return $rewritten;
    }

    /**
     * Return the "directory" portion of an upstream path, always
     * ending in a slash (or empty string for the upstream root).
     * Used for computing the base-href so relative URLs in the
     * returned page resolve against the right proxy prefix.
     *
     *   'admin/'            → 'admin/'
     *   'admin/stats.xsl'   → 'admin/'
     *   'status.xsl'        → ''
     *   ''                  → ''
     */
    private function upstreamDir(string $upstreamPath): string
    {
        $upstreamPath = ltrim($upstreamPath, '/');
        if ($upstreamPath === '' || str_ends_with($upstreamPath, '/')) {
            return $upstreamPath;
        }
        $slash = strrpos($upstreamPath, '/');
        if ($slash === false) {
            return '';
        }
        return substr($upstreamPath, 0, $slash + 1);
    }
}
