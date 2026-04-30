<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\UploadCallRecordingJob;
use App\Models\CallRecording;
use App\Models\RtpengineNode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic spool watcher for rtpengine recording-daemon output.
 *
 * Walks each rtpengine node's spool directory (mounted into the
 * Laravel container as `/var/spool/rtpengine-{N}/`), picks up
 * any finalized files (recording-daemon writes a `.done` marker
 * — or, with `output-single=yes`, the bare `.wav` exists once
 * the call ends), parses the SIP Call-ID + direction from the
 * filename, and dispatches an `UploadCallRecordingJob` per file.
 *
 * Filename pattern matches recording-daemon's default
 * `metadata-pattern = call-id-%c--leg-%l.wav` (with `%c` =
 * Call-ID, `%l` = leg index `0` / `1` mapped to caller_in /
 * caller_out). We tolerate slight variations because operators
 * sometimes tweak the pattern; anything we can't parse is left
 * on disk for human triage with a warning logged.
 *
 * Idempotent — the upload job deletes the source file on
 * success, so a re-run picks up only files that haven't been
 * processed yet.
 */
class UploadRtpengineRecordings extends Command
{
    protected $signature = 'orbital:upload-recordings {--node= : Limit to one node (hostname)}';

    protected $description = 'Drain rtpengine recording-daemon spool dirs into CallRecording rows';

    public function handle(): int
    {
        $nodes = RtpengineNode::active()->orderBy('sort_order')->get();
        if ($only = $this->option('node')) {
            $nodes = $nodes->where('hostname', $only)->values();
        }
        if ($nodes->isEmpty()) {
            $this->warn('No active rtpengine nodes registered.');
            return self::SUCCESS;
        }

        $totalDispatched = 0;
        foreach ($nodes as $node) {
            $localBase = $this->localSpoolFor($node->hostname);
            if (! is_dir($localBase)) {
                $this->line("  skip {$node->hostname} — spool not mounted at {$localBase}");
                continue;
            }

            $count = $this->drain($localBase, $node->hostname);
            $totalDispatched += $count;
            $this->line("  {$node->hostname}: dispatched {$count} upload(s)");
        }

        $this->info("Done. {$totalDispatched} upload job(s) dispatched.");
        return self::SUCCESS;
    }

    /**
     * Walk one spool dir, dispatch upload jobs for every recognized
     * finalized file. Returns the count actually dispatched.
     */
    protected function drain(string $base, string $hostname): int
    {
        $dispatched = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iter as $path) {
            $path = (string) $path;
            // Only handle audio files. recording-daemon writes WAVs by
            // default; pcap mode (Phase 1a default before recording-
            // daemon is wired) uses .pcap which we ignore for now —
            // those are operator-debug artifacts, not user-facing
            // recordings.
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($ext, ['wav', 'mp3', 'opus'], true)) {
                continue;
            }

            // Skip files actively being written. recording-daemon
            // appends `.tmp` while the audio is still flushing and
            // renames on close — checking the absence of a sibling
            // `.tmp` for the same basename is the cheap correctness
            // signal.
            if (is_file($path.'.tmp') || str_ends_with($path, '.tmp')) {
                continue;
            }

            $parsed = $this->parseFilename(basename($path));
            if ($parsed === null) {
                Log::warning('rtpengine spool: could not parse filename', [
                    'node' => $hostname,
                    'file' => $path,
                ]);
                continue;
            }

            UploadCallRecordingJob::dispatch(
                $path,
                $parsed['sip_call_id'],
                $parsed['direction'],
                $parsed['leg_uuid'],
                CallRecording::SOURCE_RTPENGINE_EDGE,
            );
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Parse recording-daemon filename into (sip_call_id, direction, leg_uuid).
     *
     * Matches the default `metadata-pattern = call-id-%c--leg-%l.wav`
     * shape and a few common variations. Returns null when nothing
     * lines up so the caller can warn + skip.
     *
     * @return array{sip_call_id: string, direction: string, leg_uuid: string}|null
     */
    protected function parseFilename(string $name): ?array
    {
        // call-id-<id>--leg-<0|1>.wav (and variants where leg is
        // already `recv` / `send` thanks to recording-daemon's
        // direction-of-flow naming).
        if (preg_match('/^call-id-(?<id>[^.\/]+)--leg-(?<leg>[^.\/]+)\.[a-z0-9]+$/', $name, $m)) {
            return [
                'sip_call_id' => $m['id'],
                'direction' => $this->mapDirection($m['leg']),
                'leg_uuid' => $m['id'],
            ];
        }

        // <call-id>-recv.wav / <call-id>-send.wav (recording-daemon's
        // shorthand for `output-single=yes` mode).
        if (preg_match('/^(?<id>[^.\/]+)-(?<dir>recv|send)\.[a-z0-9]+$/', $name, $m)) {
            return [
                'sip_call_id' => $m['id'],
                'direction' => $m['dir'] === 'recv' ? CallRecording::DIRECTION_CALLER_IN : CallRecording::DIRECTION_CALLER_OUT,
                'leg_uuid' => $m['id'],
            ];
        }

        return null;
    }

    protected function mapDirection(string $leg): string
    {
        return match ($leg) {
            '0', 'recv', 'caller_in' => CallRecording::DIRECTION_CALLER_IN,
            '1', 'send', 'caller_out' => CallRecording::DIRECTION_CALLER_OUT,
            default => CallRecording::DIRECTION_CALLER_IN,
        };
    }

    /**
     * Where the orbital.test container sees this node's spool.
     * Docker-compose mounts the node's named volume read-only at
     * `/var/spool/rtpengine/{hostname}` so the watcher has a
     * single Laravel-side path per node.
     */
    protected function localSpoolFor(string $hostname): string
    {
        return "/var/spool/rtpengine/{$hostname}";
    }
}
