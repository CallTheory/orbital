<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Sso;

use App\Services\Auth\SsoJwtSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entry point for the Redis Commander SSO flow.
 *
 * Signs an HS256 JWT for the current Laravel user, then 302s into
 * `http://{host}:8082/sso?access_token=<jwt>`. Redis Commander
 * validates the token against its SSO_JWT_SECRET (which matches
 * ours from REDIS_COMMANDER_SSO_SECRET) and lands the user inside
 * the key browser with a bearer-token cookie set.
 *
 * Host resolution: we use the incoming request's host so the flow
 * works unchanged whether the admin panel is reached on localhost,
 * a LAN IP, a custom dev hostname, or a real domain — whatever the
 * browser hit to get here is where it'll return to.
 *
 * Permission gating lives on the route via `middleware('tool:tooling.redis_commander')`,
 * not this controller. Keeping the action small + delegating to the
 * middleware alias means the same permission check applies to every
 * tool SSO route without duplicating logic per controller.
 */
class RedisCommanderSsoController
{
    public function __construct(private readonly SsoJwtSigner $signer) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $jwt = $this->signer->signForRedisCommander($user);

        // Commander's public port is pinned in compose (default 8082).
        // We hardcode the port but resolve the host dynamically.
        $port = config('services.redis_commander.public_port', 8082);
        $url = "http://{$request->getHost()}:{$port}/sso?access_token={$jwt}";

        return redirect()->away($url);
    }
}
