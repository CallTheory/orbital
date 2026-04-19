<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Sso;

use App\Services\Auth\HAProxyStatsProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HAProxyStatsProxyController
{
    public function __construct(private readonly HAProxyStatsProxy $proxy) {}

    public function forward(Request $request, string $path = ''): Response
    {
        return $this->proxy->forward($request, $path);
    }
}
