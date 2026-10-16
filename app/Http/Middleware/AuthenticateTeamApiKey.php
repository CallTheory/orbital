<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TeamApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a client's API key (`Authorization: Bearer orb_...`) and
 * exposes the owning Team as `$request->attributes->get('team')`.
 *
 * Lookup is by sha256 of the presented token against a unique column, so
 * no secret is compared in application code.
 */
class AuthenticateTeamApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $key = $token
            ? TeamApiKey::query()->active()->where('token_hash', TeamApiKey::hashToken($token))->with('team')->first()
            : null;

        if ($key === null || $key->team === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        // Touch at most once a minute so a busy integration doesn't write per request.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('team', $key->team);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
