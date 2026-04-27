<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallLog;
use App\Models\Team;
use App\Services\Telephony\CallRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Walks call_logs past every client's retention window, deletes the
 * recording objects from object storage, and clears the path columns.
 *
 * Retention is per-client: we look up the team's effective recording
 * policy (client overrides → platform default) and consider any call
 * whose `ended_at` (or `started_at` fallback) is older than
 * `retention_days` to be eligible for pruning.
 *
 * Extension-level overrides don't matter for retention — an extension
 * can opt *in* or *out* of recording, but the retention window is a
 * client-billing concern, not a per-extension one. An orphaned call
 * (no team) falls back to the platform default.
 *
 * Idempotent: a second run finds the columns already null and skips.
 * Safe to schedule daily.
 */
class PruneExpiredCallRecordingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly ?int $teamIdFilter = null,
    ) {}

    public function handle(CallRecordingService $recording): void
    {
        $disk = (string) config('telephony.recording.storage_disk', 's3');
        $platformRetention = (int) config('telephony.recording.retention_days', 90);

        // Cache per-team retention so we don't rebuild the policy for
        // every row (clients typically have hundreds to thousands of
        // calls against a single retention value).
        $retentionByTeam = [];
        $resolveRetention = function (?int $teamId) use (&$retentionByTeam, $recording, $platformRetention): int {
            if ($teamId === null) {
                return $platformRetention;
            }
            if (isset($retentionByTeam[$teamId])) {
                return $retentionByTeam[$teamId];
            }
            $team = Team::withoutGlobalScope('team')->find($teamId);
            $retentionByTeam[$teamId] = $team
                ? $recording->resolveForTeam($team)->retentionDays
                : $platformRetention;

            return $retentionByTeam[$teamId];
        };

        $totalRows = 0;
        $totalObjects = 0;
        $totalBytes = 0;

        // We chunk over the absolute superset (any recording_path set).
        // The cutoff comparison is per-row inside the loop because
        // different rows have different team retention windows.
        $query = CallLog::query()
            ->withoutGlobalScope('team')
            ->where(function ($q) {
                $q->whereNotNull('recording_path')
                    ->orWhereNotNull('recording_rx_path')
                    ->orWhereNotNull('recording_tx_path');
            });

        if ($this->teamIdFilter !== null) {
            $query->where('team_id', $this->teamIdFilter);
        }

        $query->orderBy('id')->chunkById(500, function ($calls) use (
            $resolveRetention, $disk, &$totalRows, &$totalObjects, &$totalBytes
        ) {
            foreach ($calls as $call) {
                /** @var CallLog $call */
                $retentionDays = $resolveRetention($call->team_id);
                if ($retentionDays <= 0) {
                    continue; // 0 or negative = keep forever
                }

                $anchor = $call->ended_at ?? $call->started_at ?? $call->created_at;
                if (! $anchor || $anchor->diffInDays(now()) < $retentionDays) {
                    continue;
                }

                $paths = array_filter([
                    $call->recording_path,
                    $call->recording_rx_path,
                    $call->recording_tx_path,
                ]);
                if (empty($paths)) {
                    continue;
                }

                foreach ($paths as $path) {
                    try {
                        if (Storage::disk($disk)->exists($path)) {
                            $size = (int) (Storage::disk($disk)->size($path) ?: 0);
                            Storage::disk($disk)->delete($path);
                            $totalObjects++;
                            $totalBytes += $size;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('recording prune: delete failed', [
                            'call_log_id' => $call->id,
                            'path' => $path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $call->update([
                    'recording_path' => null,
                    'recording_rx_path' => null,
                    'recording_tx_path' => null,
                    'recording_size_bytes' => null,
                ]);
                $totalRows++;
            }
        });

        Log::info('recording prune: finished', [
            'rows_cleared' => $totalRows,
            'objects_deleted' => $totalObjects,
            'bytes_freed' => $totalBytes,
            'team_filter' => $this->teamIdFilter,
        ]);
    }
}
