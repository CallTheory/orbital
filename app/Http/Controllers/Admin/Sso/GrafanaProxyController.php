<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Sso;

use App\Services\Auth\GrafanaProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin HTTP adapter for `App\Services\Auth\GrafanaProxy`. The heavy
 * lifting — header prep, forwarding, response building, WebSocket
 * short-circuit — lives in the service class; the controller exists
 * so route registration can reference a standard "class@method" pair.
 *
 * The route that hits this controller is
 * `Route::any('admin/grafana/{path?}')` with path regex `.*`, so the
 * `$path` argument carries whatever came after `/admin/grafana/`.
 * Permission gating lives on the route via
 * `middleware('tool:tooling.grafana')` — this controller never needs
 * to check permissions itself.
 */
class GrafanaProxyController
{
    public function __construct(private readonly GrafanaProxy $proxy) {}

    public function forward(Request $request, string $path = ''): Response
    {
        return $this->proxy->forward($request, $path);
    }
}
