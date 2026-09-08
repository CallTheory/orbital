<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Metrics\Exposition;
use App\Services\Metrics\PlatformMetricsCollector;
use App\Services\Metrics\TelephonyMetricsCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Gathers deployment-wide metrics and pushes them to Pushgateway.
 *
 * Scheduled every minute with onOneServer() (routes/console.php), so
 * exactly one replica publishes exactly one copy of each series. That's
 * the whole reason these numbers are pushed rather than scraped: they
 * describe the installation, not the replica, and having every app node
 * expose them would leave Prometheus with N copies of each and every
 * dashboard query needing a `max without(instance)` wrapper to avoid
 * silently multiplying the totals by the replica count.
 *
 *   php artisan orbital:collect-metrics
 *   php artisan orbital:collect-metrics --print   # stdout, no push
 *
 * `--print` is the debugging path: it renders exactly what would be
 * pushed, so a metric that looks wrong on a dashboard can be traced
 * without a Prometheus round trip.
 */
class CollectMetricsCommand extends Command
{
    protected $signature = 'orbital:collect-metrics
        {--print : Render the exposition to stdout instead of pushing it}';

    protected $description = 'Collect platform + telephony metrics and push them to Pushgateway';

    public function handle(
        PlatformMetricsCollector $platform,
        TelephonyMetricsCollector $telephony,
    ): int {
        // Both collectors write into ONE exposition rather than being
        // concatenated, so a family touched by both is declared once and
        // Prometheus doesn't see a duplicate # TYPE line.
        $exposition = new Exposition;
        $platform->collect($exposition);
        $telephony->collect($exposition);

        $rendered = $exposition->render();

        if ($rendered === '') {
            $this->warn('No metrics collected.');

            return self::SUCCESS;
        }

        if ($this->option('print')) {
            $this->line($rendered);

            return self::SUCCESS;
        }

        if (! config('metrics.pushgateway.enabled', true)) {
            $this->info('Pushgateway disabled; nothing pushed.');

            return self::SUCCESS;
        }

        $url = rtrim((string) config('metrics.pushgateway.url'), '/')
            .'/metrics/job/'.rawurlencode((string) config('metrics.pushgateway.job'));

        try {
            // PUT, not POST: PUT replaces every series in the group,
            // so a client whose call count drops to zero has its series
            // removed rather than frozen at its last non-zero value
            // forever. With POST, a decommissioned client would keep
            // reporting yesterday's traffic indefinitely.
            $response = Http::timeout((int) config('metrics.pushgateway.timeout', 5))
                ->withBody($rendered, 'text/plain')
                ->put($url);
        } catch (\Throwable $e) {
            $this->error('Push failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Pushgateway returned '.$response->status().': '.$response->body());

            return self::FAILURE;
        }

        $this->info('Pushed '.substr_count($rendered, "\n").' lines to '.$url);

        return self::SUCCESS;
    }
}
