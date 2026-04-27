<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallLog;
use App\Services\Telephony\CallRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads a completed call's three recording files (mix / rx / tx)
 * from the Asterisk spool volume to the configured object storage
 * disk, updates the matching CallLog row, and deletes the local
 * copies once the upload is confirmed.
 *
 * Dispatched by the call-event pipeline when Asterisk reports a call
 * has ended (`Hangup` AMI event) — that's when we know all three
 * MixMonitor files have been flushed to disk.
 *
 * Idempotent by design — if the job runs twice against the same
 * call, the second run is a no-op because the local files are gone
 * after the first successful upload.
 */
class UploadCallRecordingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public readonly int $callLogId,
    ) {}

    public function handle(CallRecordingService $recording): void
    {
        $call = CallLog::find($this->callLogId);
        if (! $call || ! $call->unique_id || ! $call->team_id) {
            return;
        }

        $disk = (string) config('telephony.recording.storage_disk', 's3');
        $format = (string) config('telephony.recording.format', 'wav');

        // Local paths inside the orbital.test container, bound to the
        // asterisk-recordings volume. Asterisk writes files here via
        // MixMonitor's rx()/t() options (see the mix-monitor blade).
        $localBase = $this->localBaseDirFor($call);
        $files = [
            'mix' => $localBase."/{$call->unique_id}-mix.{$format}",
            'rx' => $localBase."/{$call->unique_id}-rx.{$format}",
            'tx' => $localBase."/{$call->unique_id}-tx.{$format}",
        ];

        $remotePaths = [
            'mix' => $recording->pathFor((int) $call->team_id, $call->unique_id.'-mix', $format),
            'rx' => $recording->pathFor((int) $call->team_id, $call->unique_id.'-rx', $format),
            'tx' => $recording->pathFor((int) $call->team_id, $call->unique_id.'-tx', $format),
        ];

        $totalBytes = 0;
        $uploaded = [];

        foreach ($files as $key => $local) {
            if (! is_file($local)) {
                continue;
            }
            try {
                $stream = fopen($local, 'rb');
                Storage::disk($disk)->writeStream($remotePaths[$key], $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $totalBytes += filesize($local) ?: 0;
                $uploaded[$key] = $remotePaths[$key];
                // Remove the local copy once the upload is confirmed.
                @unlink($local);
            } catch (\Throwable $e) {
                Log::error('recording upload failed', [
                    'call_log_id' => $call->id,
                    'leg' => $key,
                    'local' => $local,
                    'remote' => $remotePaths[$key],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($uploaded)) {
            return;
        }

        $call->update([
            'recording_path' => $uploaded['mix'] ?? $call->recording_path,
            'recording_rx_path' => $uploaded['rx'] ?? $call->recording_rx_path,
            'recording_tx_path' => $uploaded['tx'] ?? $call->recording_tx_path,
            'recording_size_bytes' => $totalBytes,
        ]);
    }

    /**
     * Match the directory pattern the Asterisk dialplan writes to:
     *   /var/spool/asterisk/monitor/clients/{team_id}/{YYYY}/{MM}/
     *
     * The dialplan uses `${STRFTIME(${EPOCH},,%Y/%m)}` at call time,
     * which resolves to the year/month the call STARTED on. We use
     * the CallLog's started_at (falls back to now) so timezone drift
     * can't make us look in the wrong folder.
     */
    protected function localBaseDirFor(CallLog $call): string
    {
        $when = $call->started_at ?? now();
        $ym = $when->format('Y/m');

        return "/var/spool/asterisk/monitor/clients/{$call->team_id}/{$ym}";
    }
}
