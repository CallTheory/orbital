<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telephony\CdrIngester;
use Illuminate\Console\Command;

/**
 * Builds Call History from the call records Asterisk writes to Postgres.
 * Scheduled every minute; see CdrIngester.
 */
class IngestCdrsCommand extends Command
{
    protected $signature = 'orbital:ingest-cdrs {--limit=500 : Most calls to process in one run}';

    protected $description = 'Turn Asterisk call detail records into Call History';

    public function handle(CdrIngester $ingester): int
    {
        $result = $ingester->ingest(max(1, (int) $this->option('limit')));
        $pruned = $ingester->pruneRaw();

        if ($result['calls'] > 0 || $pruned > 0 || $this->output->isVerbose()) {
            $this->info(sprintf(
                '%d call(s): %d logged, %d without a client; %d old raw record(s) pruned',
                $result['calls'], $result['logged'], $result['skipped'], $pruned,
            ));
        }

        return self::SUCCESS;
    }
}
