<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranscribeVoicemailJob;
use App\Models\Team;
use App\Models\Voicemail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Asterisk externnotify webhook.
 *
 * Called by docker/asterisk/notify-voicemail.sh after Asterisk finishes
 * writing a voicemail WAV. Creates a Voicemail row with
 * transcription_status=pending and dispatches the transcription job
 * onto the default queue; the job handles STT + archiving + email.
 *
 * Auth: shared secret via X-Voicemail-Token header, matched against
 * config('services.voicemail.webhook_token'). No Sanctum — the Asterisk
 * container doesn't speak OAuth and we don't want to.
 */
class VoicemailWebhookController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $expected = (string) config('services.voicemail.webhook_token', '');
        $given = (string) $request->header('X-Voicemail-Token', '');
        if ($expected === '' || ! hash_equals($expected, $given)) {
            abort(401);
        }

        $data = $request->validate([
            'mailbox' => 'required|string',
            'new_count' => 'nullable|integer',
            'recording_path' => 'required|string',
            'duration_seconds' => 'nullable|integer',
            'caller_id_num' => 'nullable|string',
            'caller_id_name' => 'nullable|string',
        ]);

        // Mailbox number = client account_number (set by
        // AsteriskConfigService::generateVoicemail()).
        $team = Team::query()
            ->where('account_number', (int) $data['mailbox'])
            ->first();

        if (! $team) {
            Log::warning('voicemail-webhook: no client matches mailbox', [
                'mailbox' => $data['mailbox'],
            ]);
            // 202: we accept the webhook to keep Asterisk happy, but
            // there's nothing to do here. Logs capture the miss.
            return response()->json(['ok' => true, 'matched' => false], 202);
        }

        // Idempotent on (mailbox, recording_path). Both Asterisks
        // see the voicemail spool via a shared volume, so theoretically
        // externnotify could fire twice for the same WAV (e.g. a
        // retried webhook after a transient failure, or a future
        // change that has each node scan the spool). Keying the
        // lookup on the recording path — unique per message because
        // Asterisk serialises mailbox filenames — means a duplicate
        // webhook hits the same row and doesn't re-queue the job.
        $voicemail = Voicemail::firstOrCreate(
            [
                'mailbox' => (string) $data['mailbox'],
                'recording_path' => (string) $data['recording_path'],
            ],
            [
                'team_id' => $team->id,
                'caller_id_num' => $data['caller_id_num'] ?? null,
                'caller_id_name' => $data['caller_id_name'] ?? null,
                'duration_seconds' => (int) ($data['duration_seconds'] ?? 0),
                'transcription_status' => 'pending',
            ],
        );

        // Only dispatch the transcription job when THIS request
        // actually created the row. `wasRecentlyCreated` tracks
        // whether firstOrCreate inserted or matched — perfect
        // signal for "did we see this WAV before?".
        if ($voicemail->wasRecentlyCreated) {
            TranscribeVoicemailJob::dispatch($voicemail->id);
        }

        return response()->json([
            'ok' => true,
            'voicemail_id' => $voicemail->id,
            'created' => $voicemail->wasRecentlyCreated,
        ], 202);
    }
}
