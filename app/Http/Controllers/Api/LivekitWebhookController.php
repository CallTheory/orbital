<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestLivekitEgressJob;
use App\Models\CallSessionState;
use App\Services\Messages\PartialMessagePolicy;
use App\Services\Messages\SessionMessageWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives LiveKit server-side webhook events at
 * `POST /api/livekit/webhook`. Two events are acted on:
 *
 *   - `egress_ended` — a per-participant track file has been
 *     finalized in the configured S3 bucket and is ready to
 *     register as a `CallRecording` row.
 *   - `room_finished` — the call is definitively over. Finalises
 *     the matching call session so a partial message capture can
 *     be written for clients whose policy keeps them, even if the
 *     agent worker died without reporting the end itself.
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

        if ($event === 'room_finished') {
            $this->finaliseSession($payload['room']['name'] ?? null);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The room closed, so the call is definitively over.
     *
     * The agent worker normally reports this itself via
     * POST /api/call-sessions/{key}/end, and that path is the fast one.
     * This is the backstop for a worker that crashed, was OOM-killed, or
     * lost its connection — without it, a session whose worker died
     * would sit un-ended forever and any partial capture the client
     * asked us to keep would never be written.
     *
     * markEnded() is idempotent, so the two paths racing is fine and
     * expected: whichever arrives first sets the reason.
     */
    protected function finaliseSession(?string $roomName): void
    {
        if (! $roomName) {
            return;
        }

        // The LiveKit room name IS the session key — that's the contract
        // the agent worker follows when it opens a session.
        $state = CallSessionState::where('session_key', $roomName)->first();

        if (! $state) {
            return;
        }

        $state->markEnded(PartialMessagePolicy::REASON_SESSION_ABANDONED);

        app(SessionMessageWriter::class)->write($state->fresh());
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
