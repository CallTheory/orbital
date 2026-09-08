<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchMessageMediaJob;
use App\Models\MessageEntry;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Re-queues FetchMessageMediaJob for MMS attachments that are still
 * nothing but a carrier URL.
 *
 * Every entry written before the fetch-and-store path existed — and
 * any entry whose fetch failed at the time, since FetchMessageMediaJob
 * deliberately leaves the provider URL in place rather than losing the
 * attachment — points at media we do not hold a copy of. Those URLs
 * expire on the carrier's schedule, so this is on a clock: the longer
 * it waits, the more of them return 404 and the attachment is gone for
 * good.
 *
 * The command only dispatches. FetchMessageMediaJob is already
 * idempotent (an item carrying a `storage_path` is skipped), so a
 * re-run costs a no-op job per entry rather than a duplicate object,
 * and a run interrupted halfway can simply be run again.
 *
 * Deliberately not scheduled. It is a one-off migration aid with a
 * cost proportional to how much media you have, and firing it on a
 * timer would re-walk the whole table forever to find nothing.
 *
 *   php artisan orbital:backfill-message-media --dry-run
 *   php artisan orbital:backfill-message-media --since=2026-06-01
 *   php artisan orbital:backfill-message-media --limit=500
 */
class BackfillMessageMediaCommand extends Command
{
    protected $signature = 'orbital:backfill-message-media
        {--dry-run : Report what would be queued without dispatching anything.}
        {--since= : Only entries received on or after this date (YYYY-MM-DD).}
        {--limit=0 : Stop after dispatching this many entries. 0 for no limit.}
        {--chunk=200 : Rows to read per query.}';

    protected $description = 'Queue FetchMessageMediaJob for MMS attachments still held only on the carrier.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));
        $since = $this->option('since')
            ? Carbon::parse((string) $this->option('since'))->startOfDay()
            : null;

        // withoutGlobalScopes: this is a platform-wide maintenance
        // sweep run from the CLI, where there is no team context to
        // scope to and every tenant's media matters equally.
        $query = MessageEntry::withoutGlobalScopes()
            ->whereNotNull('media')
            ->orderBy('id');

        if ($since) {
            $query->where('occurred_at', '>=', $since);
        }

        $scanned = 0;
        $pending = 0;
        $items = 0;

        $this->info($dryRun
            ? 'Scanning for message media still held only on the carrier (dry run)...'
            : 'Queueing fetches for message media still held only on the carrier...');

        // The media filter has to happen in PHP rather than in SQL:
        // the column is `json`, not `jsonb`, and Postgres gives `json`
        // no equality or containment operators to test against. The
        // whereNotNull above already discards every entry with no
        // attachment at all, which is the overwhelming majority.
        $query->chunkById($chunk, function ($entries) use ($dryRun, $limit, &$scanned, &$pending, &$items): bool {
            foreach ($entries as $entry) {
                $scanned++;
                $unstored = $this->unstoredCount($entry);

                if ($unstored === 0) {
                    continue;
                }

                $pending++;
                $items += $unstored;

                if (! $dryRun) {
                    FetchMessageMediaJob::dispatch($entry->id);
                }

                if ($limit > 0 && $pending >= $limit) {
                    return false;
                }
            }

            return true;
        });

        $this->newLine();
        $this->line("  Entries scanned:        {$scanned}");
        $this->line("  Entries needing fetch:  {$pending}");
        $this->line("  Attachments to fetch:   {$items}");

        if ($pending === 0) {
            $this->info('Nothing to do — every attachment already has a stored copy.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment('Dry run: nothing was dispatched. Re-run without --dry-run to queue the fetches.');

            return self::SUCCESS;
        }

        $this->info("Dispatched {$pending} job(s) to the inbound-messages queue. Horizon must be running for them to drain.");

        return self::SUCCESS;
    }

    /**
     * Attachments on this entry that still point only at the carrier.
     */
    private function unstoredCount(MessageEntry $entry): int
    {
        $count = 0;

        foreach ((array) ($entry->media ?? []) as $item) {
            $item = (array) $item;

            if (! empty($item['storage_path'])) {
                continue;
            }

            if (! empty($item['url'])) {
                $count++;
            }
        }

        return $count;
    }
}
