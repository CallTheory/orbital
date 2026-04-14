<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Bootstrap\BootstrapRegistry;
use App\Services\Bootstrap\BootstrapStatus;
use Illuminate\Database\Seeder;

/**
 * Runs every registered Bootstrapper as part of the normal seed flow
 * so `migrate:fresh --seed` leaves the whole stack in a usable state:
 *   - MinIO buckets created
 *   - pgvector extension enabled
 *   - Ollama default model pulled (if the container is running)
 *   - Asterisk configs generated (if permissions allow)
 *   - LiveKit dispatch rules built
 *
 * Optional services (Ollama, Icecast) that are unreachable get logged
 * and skipped — running this seeder in a bare dev environment without
 * those containers shouldn't fail the whole seed.
 *
 * Individual failures are reported to stdout for the seed output but
 * don't halt seeding. Operators get the full status via:
 *   php artisan orbital:bootstrap --status
 * or through the System Setup page in Filament.
 */
class SystemBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(BootstrapRegistry::class);

        foreach ($registry->all() as $key => $bootstrapper) {
            try {
                $report = $bootstrapper->install();
                $label = $report->status->label();
                $this->command?->getOutput()?->writeln(
                    "  <fg=gray>{$bootstrapper->name()}</> — <fg={$this->colorFor($report->status)}>{$label}</>"
                );
            } catch (\Throwable $e) {
                $this->command?->getOutput()?->writeln(
                    "  <fg=gray>{$bootstrapper->name()}</> — <fg=red>failed: {$e->getMessage()}</>"
                );
                // Optional services fail soft; required services halt
                // only if we re-raise. We choose to keep going either
                // way because a seeded environment should ALWAYS leave
                // you with a clickable app to diagnose from.
            }
        }
    }

    protected function colorFor(BootstrapStatus $status): string
    {
        return match ($status) {
            BootstrapStatus::Installed => 'green',
            BootstrapStatus::Partial => 'yellow',
            BootstrapStatus::Missing => 'gray',
            BootstrapStatus::Error => 'red',
        };
    }
}
