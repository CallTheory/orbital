<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agent worker authenticates with the shared secret in
 * `services.agent_worker.token` (the chart's agentWorker.token), not a
 * Sanctum token. Accept that secret; otherwise fall back to Sanctum so
 * personal access tokens keep working. Only the worker secret marks the
 * request as `agent_worker`, which is what releases provider keys.
 */
class AuthenticateAgentWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.agent_worker.token');

        if ($expected !== '' && hash_equals($expected, (string) $request->bearerToken())) {
            $request->attributes->set('agent_worker', true);

            return $next($request);
        }

        return app(Authenticate::class)->handle($request, $next, 'sanctum');
    }
}
