<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallLog;
use App\Models\CallRecording;
use App\Services\Telephony\CallRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Picks up one finalized recording file from a rtpengine
 * recording-daemon spool, uploads it to the configured object
 * storage disk, and writes a `CallRecording` row tied back to
 * the matching `CallLog` via SIP Call-ID.
 *
 * Dispatched by `orbital:upload-recordings` (the spool watcher
 * scheduled command), one job per finalized file. The watcher
 * resolves the sip_call_id + direction from the recording-daemon
 * filename pattern before dispatch.
 *
 * Idempotent — if the same file is dispatched twice, the second
 * run sees it's already gone (we delete after upload) and exits
 * cleanly. We also de-dupe on (sip_call_id, direction) at the
 * `call_recordings` level so a re-run after a partial failure
 * doesn't create duplicate rows.
 */
class UploadCallRecordingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    /**
     * @param  string  $sourcePath  Absolute path to the file on the local
     *   filesystem (the spool volume mounted into the orbital.test container).
     * @param  string  $sipCallId  SIP Call-ID stamped on the file by
     *   the recording-daemon metadata; correlates to `call_logs.sip_call_id`.
     * @param  string  $direction  CallRecording::DIRECTION_* — caller_in / caller_out.
     * @param  string|null  $legUuid  Optional grouping key for paired files
     *   (typically equals sip_call_id; carried separately so multi-leg
     *   sessions can stitch differently if needed).
     * @param  string  $source  CallRecording::SOURCE_* — defaults to rtpengine_edge.
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $sipCallId,
        public readonly string $direction,
        public readonly ?string $legUuid = null,
        public readonly string $source = CallRecording::SOURCE_RTPENGINE_EDGE,
    ) {}

    public function handle(CallRecordingService $recording): void
    {
        if (! is_file($this->sourcePath)) {
            // Either the file got cleaned up between watcher dispatch
            // and job pickup, or this is a re-run after the upload
            // succeeded. Either way: nothing to do.
            return;
        }

        $call = CallLog::query()
            ->withoutGlobalScope('team')
            ->where('sip_call_id', $this->sipCallId)
            ->orderByDesc('id')
            ->first();

        if (! $call) {
            // Recording landed before the CallLog row did — push the
            // job back so we can retry once the call-event ingest has
            // caught up. Two minutes is well past every reasonable
            // hangup→CallLog persistence window.
            $this->release(now()->addMinutes(2));
            Log::info('recording-upload deferred: CallLog not yet present', [
                'sip_call_id' => $this->sipCallId,
                'source' => $this->sourcePath,
            ]);
            return;
        }

        // Skip duplicate uploads at the row level. The post-upload
        // delete of $sourcePath is the primary guard; this is belt-
        // and-suspenders for the re-run-after-partial-failure case.
        $existing = CallRecording::query()
            ->where('call_log_id', $call->id)
            ->where('source', $this->source)
            ->where('direction', $this->direction)
            ->where('leg_uuid', $this->legUuid)
            ->first();
        if ($existing) {
            @unlink($this->sourcePath);
            return;
        }

        $disk = (string) config('telephony.recording.storage_disk', 's3');
        $format = pathinfo($this->sourcePath, PATHINFO_EXTENSION) ?: 'wav';
        $remote = $recording->pathFor(
            (int) $call->team_id,
            $this->sipCallId,
            $format,
            $this->direction,
        );

        try {
            $stream = fopen($this->sourcePath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('cannot read recording file');
            }
            Storage::disk($disk)->writeStream($remote, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            Log::error('recording upload failed', [
                'sip_call_id' => $this->sipCallId,
                'direction' => $this->direction,
                'source' => $this->sourcePath,
                'remote' => $remote,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $sizeBytes = (int) (filesize($this->sourcePath) ?: 0);

        CallRecording::create([
            'team_id' => $call->team_id,
            'call_log_id' => $call->id,
            'source' => $this->source,
            'direction' => $this->direction,
            'leg_uuid' => $this->legUuid,
            'storage_path' => $remote,
            'format' => $format,
            'size_bytes' => $sizeBytes,
            'metadata' => [
                'spool_origin' => $this->sourcePath,
            ],
        ]);

        @unlink($this->sourcePath);
    }
}
