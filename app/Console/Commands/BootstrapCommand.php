<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapRegistry;
use App\Services\Bootstrap\BootstrapStatus;
use Illuminate\Console\Command;

/**
 * `php artisan orbital:bootstrap [service?]`
 *
 * Runs one or all system bootstrappers. With no argument, walks the
 * full registry in order, installing each service's default state.
 * With an argument, runs only that bootstrapper (handy for rerunning
 * a specific service after a config change).
 *
 * Safe to run repeatedly — every bootstrapper is idempotent.
 */
class BootstrapCommand extends Command
{
    protected $signature = 'orbital:bootstrap
                            {service? : Run only the named bootstrapper (pgvector, minio, ollama, asterisk, livekit, icecast)}
                            {--status : Report current status without making changes}';

    protected $description = 'Initialize external services (MinIO buckets, Ollama models, Asterisk configs, etc.)';

    public function handle(BootstrapRegistry $registry): int
    {
        $service = $this->argument('service');
        $statusOnly = (bool) $this->option('status');

        if ($service) {
            $b = $registry->get($service);
            if (! $b) {
                $this->error("Unknown bootstrapper: {$service}");
                $available = implode(', ', array_keys($registry->all()));
                $this->line("Available: {$available}");

                return self::FAILURE;
            }

            return $this->runOne($b, $statusOnly);
        }

        $hasErrors = false;
        foreach ($registry->all() as $b) {
            if ($this->runOne($b, $statusOnly) === self::FAILURE) {
                $hasErrors = true;
            }
            $this->newLine();
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    protected function runOne(Bootstrapper $b, bool $statusOnly): int
    {
        $this->info("── {$b->name()} ({$b->key()})");

        $report = $statusOnly ? $b->status() : $b->install();

        $label = $report->status->label();
        $line = "  [{$label}] {$report->message}";
        match ($report->status) {
            BootstrapStatus::Installed => $this->info($line),
            BootstrapStatus::Partial => $this->warn($line),
            BootstrapStatus::Missing => $this->line($line),
            BootstrapStatus::Error => $this->error($line),
        };

        foreach ($report->steps as $step) {
            $icon = $step['ok'] ? '✓' : '✗';
            $detail = $step['detail'] ? " — {$step['detail']}" : '';
            $this->line("    {$icon} {$step['label']}{$detail}");
        }

        return $report->status === BootstrapStatus::Error ? self::FAILURE : self::SUCCESS;
    }
}
