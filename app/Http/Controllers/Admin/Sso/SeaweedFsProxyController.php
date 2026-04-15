<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Sso;

use App\Services\Auth\SeaweedFsProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin HTTP adapter for `App\Services\Auth\SeaweedFsProxy`.
 *
 * Two methods for the two upstream surfaces — filer web UI
 * and master cluster admin. The service class handles the
 * actual forwarding + header prep + HTML rewrite; the
 * controller only exists so route registration can name a
 * standard "class@method" pair.
 *
 * Both routes sit behind `middleware('tool:tooling.seaweedfs')`
 * so only super-admins or users holding the permission can
 * reach them. The upstream has NO auth of its own — the Laravel
 * gate is the whole trust boundary.
 */
class SeaweedFsProxyController
{
    public function __construct(private readonly SeaweedFsProxy $proxy) {}

    public function filer(Request $request, string $path = ''): Response
    {
        return $this->proxy->forwardFiler($request, $path);
    }

    public function master(Request $request, string $path = ''): Response
    {
        return $this->proxy->forwardMaster($request, $path);
    }
}
