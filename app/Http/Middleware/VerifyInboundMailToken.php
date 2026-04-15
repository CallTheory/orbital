<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token check for the Haraka → Laravel inbound-mail webhook.
 *
 * The Haraka container and this Laravel app share a single secret
 * stored in `INBOUND_MAIL_TOKEN`. Haraka's queue plugin sends it as
 * `Authorization: Bearer {token}` on every POST; this middleware
 * rejects anything with a missing or mismatched token.
 *
 * Same shared-secret pattern as `/api/agent-worker/heartbeat` — the
 * upstream isn't a Sanctum-issued user, just another container on
 * the internal network.
 */
class VerifyInboundMailToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.inbound_mail.token');

        if ($expected === '' || $request->bearerToken() !== $expected) {
            abort(401, 'Invalid inbound mail token');
        }

        return $next($request);
    }
}
