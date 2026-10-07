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
 * Laravel container as `/var/spool/rtpengine/{hostname}/`), picks up
 * finished recordings, parses the SIP Call-ID + direction from the
 * filename, and dispatches an `UploadCallRecordingJob` per file.
 *
 * The edge's recording-daemon is configured with
 *
 *     output-mixed
 *     output-pattern = call-id-%c--leg-%t
 *
 * so each call yields one `call-id-<Call-ID>--leg-mix.wav`. The daemon
 * appends the extension itself, rejects any pattern lacking `%c` or
 * `%t`, and adds `-1`, `-2`, ... before the extension if the name is
 * already taken. Anything we can't parse is left on disk for human
 * triage with a warning logged.
 *
 * recording-daemon writes straight to the final filename for the
 * whole call (no `.tmp` / `.done` marker) and only completes the WAV
 * header on close, so a file counts as finished once it has gone
 * unmodified for SETTLE_SECONDS. With the default 256 kB output
 * buffer, an active 8 kHz call flushes roughly every 16 seconds.
 *
 * Idempotent — the upload job deletes the source file on
 * success, so a re-run picks up only files that haven't been
 * processed yet.
 */
class UploadRtpengineRecordings extends Command
{
    /**
     * How long a recording must sit unmodified before it's treated as
     * finished. Comfortably above the daemon's buffer flush interval.
     */
    public const SETTLE_SECONDS = 120;

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

            // Skip calls still in progress — see the class docblock.
            if (! $this->isSettled($path)) {
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
     * A file is finished once nothing has written to it for
     * SETTLE_SECONDS.
     */
    protected function isSettled(string $path): bool
    {
        $mtime = @filemtime($path);

        return $mtime !== false && time() - $mtime >= self::SETTLE_SECONDS;
    }

    /**
     * Parse a recording-daemon filename into (sip_call_id, direction,
     * leg_uuid).
     *
     * Expects `call-id-<Call-ID>--leg-<%t>[-<n>].<ext>`. The Call-ID may
     * contain anything but `/` (real ones look like `a84b4c76@10.0.0.5`),
     * and is matched greedily so a Call-ID containing `--leg-` still
     * splits on the last occurrence. `-<n>` is the daemon's collision
     * suffix; it's folded into leg_uuid so a second recording of the
     * same Call-ID isn't discarded as a duplicate of the first.
     *
     * Returns null for anything else, including per-source (`%t` =
     * SSRC) files, so the caller warns and leaves them for triage.
     *
     * @return array{sip_call_id: string, direction: string, leg_uuid: string}|null
     */
    public function parseFilename(string $name): ?array
    {
        if (! preg_match('/^call-id-(?<id>[^\/]+)--leg-(?<leg>[a-z0-9_]+?)(?:-(?<dup>\d+))?\.(?:wav|mp3|opus)$/i', $name, $m)) {
            return null;
        }

        // `%t` is `mix` for the mixed output we configure; anything else
        // is an SSRC from per-source output, which we don't ingest.
        if (strtolower($m['leg']) !== 'mix') {
            return null;
        }

        $dup = $m['dup'] ?? '';

        return [
            'sip_call_id' => $m['id'],
            'direction' => CallRecording::DIRECTION_MIXED,
            'leg_uuid' => $dup === '' ? $m['id'] : "{$m['id']}-{$dup}",
        ];
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
