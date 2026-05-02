<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallLog;
use App\Models\CallRecording;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Picks up a finalized LiveKit Egress and writes a `CallRecording`
 * row per output file. Track Egress writes one file per participant
 * audio track — each AI agent gets its own WAV, each operator gets
 * their own — which gives us speaker diarization for free at the
 * recording layer (no VAD or speaker embedding needed).
 *
 * Invoked from `LivekitWebhookController` on `egress_ended` events.
 * The webhook's `egressInfo` payload carries everything we need:
 * the room name (used as the SIP Call-ID correlation key), the
 * participant identity, and the storage path the file landed at.
 *
 * Idempotent — duplicate egress_ended deliveries (LiveKit retries
 * on non-2xx) re-find the existing row and exit cleanly.
 */
class IngestLivekitEgressJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $egressInfo
     *                                            The `EgressInfo` struct LiveKit sends in the webhook body.
     *                                            Carries `egress_id`, `room_name`, `room_id`, `status`,
     *                                            `track_results[]`, `started_at`, `ended_at`, and so on.
     */
    public function __construct(
        public readonly array $egressInfo,
    ) {}

    public function handle(): void
    {
        $egressId = (string) ($this->egressInfo['egress_id'] ?? '');
        $roomName = (string) ($this->egressInfo['room_name'] ?? '');

        if ($egressId === '' || $roomName === '') {
            Log::warning('livekit egress: missing room/egress id', [
                'payload' => $this->egressInfo,
            ]);

            return;
        }

        // Convention: agent-worker creates the LiveKit room with the
        // SIP Call-ID embedded in the name (or one-leg-of-the-Call-ID
        // in the case of bridged transfers) so we can correlate the
        // Egress output back to the CallLog without a side channel.
        $callLog = CallLog::query()
            ->withoutGlobalScope('team')
            ->where(function ($q) use ($roomName) {
                $q->where('sip_call_id', $roomName)
                    ->orWhere('linked_id', $roomName)
                    ->orWhere('unique_id', $roomName);
            })
            ->orderByDesc('id')
            ->first();

        if (! $callLog) {
            Log::info('livekit egress: no CallLog match — deferring', [
                'room' => $roomName,
                'egress_id' => $egressId,
            ]);
            $this->release(now()->addMinutes(2));

            return;
        }

        $tracks = $this->egressInfo['track_results']
            ?? $this->egressInfo['file_results']
            ?? [];
        if (! is_array($tracks) || $tracks === []) {
            Log::info('livekit egress: no track results in payload', [
                'egress_id' => $egressId,
            ]);

            return;
        }

        foreach ($tracks as $track) {
            $this->upsertRecording($callLog, $egressId, $track);
        }
    }

    /**
     * Create one CallRecording row per track. Dedupes on
     * (call_log_id, source, leg_uuid, participant_identity) so a
     * webhook retry doesn't insert twice.
     *
     * @param  array<string, mixed>  $track
     */
    protected function upsertRecording(CallLog $callLog, string $egressId, array $track): void
    {
        $participant = (string) ($track['participant_identity'] ?? $track['identity'] ?? '');
        $storagePath = (string) ($track['filename'] ?? $track['location'] ?? '');
        if ($storagePath === '') {
            return;
        }

        $existing = CallRecording::query()
            ->where('call_log_id', $callLog->id)
            ->where('source', CallRecording::SOURCE_LIVEKIT_EGRESS)
            ->where('leg_uuid', $egressId)
            ->where('participant_identity', $participant ?: null)
            ->first();
        if ($existing) {
            return;
        }

        CallRecording::create([
            'team_id' => $callLog->team_id,
            'call_log_id' => $callLog->id,
            'source' => CallRecording::SOURCE_LIVEKIT_EGRESS,
            'direction' => CallRecording::DIRECTION_PARTICIPANT_TRACK,
            'leg_uuid' => $egressId,
            'participant_identity' => $participant ?: null,
            'storage_path' => $storagePath,
            'format' => $this->guessFormat($storagePath),
            'size_bytes' => (int) ($track['size'] ?? $track['bytes'] ?? 0),
            'duration_ms' => (int) (($track['duration'] ?? 0) / 1_000_000), // LK reports nanoseconds
            'metadata' => [
                'egress_id' => $egressId,
                'room' => $this->egressInfo['room_name'] ?? null,
                'started_at' => $this->egressInfo['started_at'] ?? null,
                'ended_at' => $this->egressInfo['ended_at'] ?? null,
            ],
        ]);
    }

    protected function guessFormat(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'ogg';
    }
}
