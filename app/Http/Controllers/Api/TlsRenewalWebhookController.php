<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Jobs\ReloadServicesAfterCertRenewalJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook endpoint for acme.sh's deploy hook.
 *
 * After each successful cert renewal, acme.sh's deploy-hook.sh
 * POSTs to this endpoint with the ACME_WEBHOOK_TOKEN. We dispatch
 * a queued job that reloads each service consuming the cert
 * (Asterisk, Kamailio, nginx, etc.) so they pick up the new files
 * without a manual container restart.
 *
 * Returns 202 Accepted immediately — the actual reloads happen
 * asynchronously on the queue so the deploy hook doesn't block.
 */
class TlsRenewalWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $expectedToken = (string) config('tls.webhook_token');
        $providedToken = $request->bearerToken() ?? '';

        if ($expectedToken === '' || $providedToken !== $expectedToken) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        ReloadServicesAfterCertRenewalJob::dispatch();

        return response()->json(['status' => 'accepted'], 202);
    }
}
