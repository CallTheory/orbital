<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\VoicemailReceived;
use App\Models\Voicemail;
use App\Services\Voicemail\VoicemailTranscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Fired by the voicemail externnotify webhook once Asterisk finishes
 * writing a new message. Responsible for:
 *
 *   1. Running the client's configured transcription provider (or
 *      skipping it if the client picked `none`).
 *   2. Uploading the WAV to SeaweedFS so the audio survives past
 *      Asterisk's spool retention.
 *   3. Sending the VoicemailReceived email with transcript inline
 *      + audio attached to the client's primary contact.
 *   4. Deleting the Asterisk spool copy once the upload is safe.
 *
 * Retries on transcription-provider failure — three attempts with
 * exponential backoff. If all fail the email still goes out with a
 * "(transcription failed)" placeholder so the operator isn't left
 * guessing.
 */
class TranscribeVoicemailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $voicemailId,
    ) {}

    public function backoff(): array
    {
        // 15s, 60s, 5min — mostly to ride out transient provider
        // hiccups, not long outages.
        return [15, 60, 300];
    }

    public function handle(VoicemailTranscriber $transcriber): void
    {
        $voicemail = Voicemail::query()->with('team')->find($this->voicemailId);
        if (! $voicemail || ! $voicemail->team) {
            Log::warning('transcribe-voicemail: voicemail or team missing', [
                'voicemail_id' => $this->voicemailId,
            ]);
            return;
        }

        $wavPath = (string) $voicemail->recording_path;
        if ($wavPath === '' || ! is_readable($wavPath)) {
            $voicemail->update([
                'transcription_status' => 'failed',
                'transcription_error' => "Recording unreadable at {$wavPath}",
            ]);
            Log::warning('transcribe-voicemail: recording missing', [
                'voicemail_id' => $voicemail->id,
                'path' => $wavPath,
            ]);
            // Still send the email — operator needs to know a voicemail
            // was attempted even if we can't read the WAV.
            $this->sendEmail($voicemail);
            return;
        }

        $provider = (string) ($voicemail->team->voicemail_transcription_provider ?? 'none');

        if ($provider === 'none') {
            $voicemail->update([
                'transcription_status' => 'skipped',
                'transcription_provider' => 'none',
                'transcribed_at' => now(),
            ]);
        } else {
            try {
                $transcript = $transcriber->transcribeFor($voicemail->team, $wavPath);
                $voicemail->update([
                    'transcript' => $transcript,
                    'transcription_status' => 'ok',
                    'transcription_provider' => $provider,
                    'transcription_error' => null,
                    'transcribed_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // On final failure, record the error but still email
                // the audio so the client gets their message.
                if ($this->attempts() >= $this->tries) {
                    $voicemail->update([
                        'transcription_status' => 'failed',
                        'transcription_provider' => $provider,
                        'transcription_error' => mb_substr($e->getMessage(), 0, 2000),
                    ]);
                    Log::error('transcribe-voicemail: provider failed after retries', [
                        'voicemail_id' => $voicemail->id,
                        'provider' => $provider,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    throw $e;  // let the worker retry
                }
            }
        }

        // Archive WAV to SeaweedFS under voicemails/{team_id}/{id}.wav so
        // the email attachment survives Asterisk spool rotation.
        $voicemail->refresh();
        $this->archiveToS3($voicemail, $wavPath);
        $this->sendEmail($voicemail->fresh());
    }

    protected function archiveToS3(Voicemail $voicemail, string $wavPath): void
    {
        try {
            $s3Path = "voicemails/{$voicemail->team_id}/{$voicemail->id}.wav";
            Storage::disk('s3')->put($s3Path, file_get_contents($wavPath));
            $voicemail->update(['s3_path' => $s3Path]);
            // Leave the spool WAV alone — Asterisk's `maxmsg` + admin
            // review cycle handles deletion. We've got the S3 copy.
        } catch (\Throwable $e) {
            Log::warning('transcribe-voicemail: S3 archive failed', [
                'voicemail_id' => $voicemail->id,
                'error' => $e->getMessage(),
            ]);
            // Non-fatal: email still goes out with the spool-path WAV.
        }
    }

    protected function sendEmail(Voicemail $voicemail): void
    {
        $recipient = $voicemail->team->owner?->email;
        if (! $recipient) {
            Log::warning('transcribe-voicemail: no recipient — client owner has no email', [
                'voicemail_id' => $voicemail->id,
                'team_id' => $voicemail->team_id,
            ]);
            return;
        }

        try {
            Mail::to($recipient)->send(new VoicemailReceived($voicemail));
            $voicemail->update(['emailed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('transcribe-voicemail: email send failed', [
                'voicemail_id' => $voicemail->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
