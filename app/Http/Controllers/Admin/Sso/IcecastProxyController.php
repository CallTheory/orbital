<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Sso;

use App\Services\Auth\IcecastProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin HTTP adapter for `App\Services\Auth\IcecastProxy`. The
 * proxy itself handles header prep, Basic-auth injection, and
 * response building — the controller only exists so route
 * registration can name a standard "class@method" pair.
 *
 * Route: `Route::any('admin/icecast/{path?}')` with `path` regex
 * `.*` and `tool:tooling.icecast` middleware. Empty path maps to
 * Icecast's `/admin/` URL inside the proxy.
 */
class IcecastProxyController
{
    public function __construct(private readonly IcecastProxy $proxy) {}

    public function forward(Request $request, string $path = ''): Response
    {
        return $this->proxy->forward($request, $path);
    }
}
