<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestLivekitEgressJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives LiveKit server-side webhook events at
 * `POST /api/livekit/webhook`. We currently only act on
 * `egress_ended` — that's the signal a per-participant track
 * file has been finalized in the configured S3 bucket and is
 * ready to register as a `CallRecording` row.
 *
 * Every other event passes through with a 200; LiveKit retries
 * on non-2xx so we'd rather log-and-accept unknown event types
 * than churn its retry queue.
 *
 * Auth: LiveKit signs every webhook payload with the configured
 * API key/secret as a JWT in the `Authorization` header. The
 * raw body is the JWT's `sha256` claim — we verify both before
 * handing off to the queue.
 */
class LivekitWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $authHeader = $request->header('Authorization', '');

        if (! $this->verify($body, (string) $authHeader)) {
            Log::warning('livekit webhook: signature verification failed', [
                'remote' => $request->ip(),
            ]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $payload = json_decode($body, true) ?: [];
        $event = (string) ($payload['event'] ?? '');

        if ($event === 'egress_ended') {
            // Hand the EgressInfo struct to the queue so the
            // controller returns fast even if SeaweedFS is slow.
            IngestLivekitEgressJob::dispatch($payload['egressInfo'] ?? []);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Verify the webhook's `Authorization: <jwt>` against the
     * configured LiveKit API secret. The JWT carries a `sha256`
     * claim equal to the base64-encoded SHA-256 of the raw body.
     */
    protected function verify(string $body, string $authHeader): bool
    {
        $secret = (string) config('telephony.livekit.api_secret', env('LIVEKIT_API_SECRET', ''));
        if ($secret === '' || $authHeader === '') {
            return false;
        }

        $parts = explode('.', $authHeader);
        if (count($parts) !== 3) {
            return false;
        }
        [$header, $claims, $sig] = $parts;

        // HMAC-SHA256 verification of the JWT signature.
        $expected = hash_hmac(
            'sha256',
            $header.'.'.$claims,
            $secret,
            true,
        );
        $expectedB64 = rtrim(strtr(base64_encode($expected), '+/', '-_'), '=');
        if (! hash_equals($expectedB64, $sig)) {
            return false;
        }

        // Confirm the body hash matches the JWT's `sha256` claim
        // so the payload can't be replayed against a different signature.
        $claimsJson = base64_decode(strtr($claims, '-_', '+/'), true);
        $decoded = is_string($claimsJson) ? json_decode($claimsJson, true) : null;
        $claimedHash = is_array($decoded) ? (string) ($decoded['sha256'] ?? '') : '';
        $bodyHash = rtrim(strtr(base64_encode(hash('sha256', $body, true)), '+/', '-_'), '=');

        return $claimedHash !== '' && hash_equals($claimedHash, $bodyHash);
    }
}
