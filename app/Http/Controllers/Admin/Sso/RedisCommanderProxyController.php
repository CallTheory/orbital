<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Sso;

use App\Services\Auth\RedisCommanderProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedisCommanderProxyController
{
    public function __construct(private readonly RedisCommanderProxy $proxy) {}

    public function forward(Request $request, string $path = ''): Response
    {
        return $this->proxy->forward($request, $path);
    }
}
