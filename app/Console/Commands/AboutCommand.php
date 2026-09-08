<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Licensing\SupportSubscription;
use App\Support\Release;
use Illuminate\Console\Command;

/**
 * `php artisan orbital:about` — what am I running, under what license,
 * and where is its source.
 *
 * The CLI counterpart to the About page. First command to run when
 * triaging a support report, and the answer to the AGPL section 13
 * question for anyone who has shell access rather than a browser.
 */
class AboutCommand extends Command
{
    protected $signature = 'orbital:about {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Show this installation\'s version, license, and source location';

    public function handle(SupportSubscription $support): int
    {
        if ($this->option('json')) {
            $payload = Release::toArray();
            $payload['support'] = [
                'active' => $support->isActive(),
                'tier' => $support->tier(),
                'expires_at' => $support->expiresAt()?->toIso8601String(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  <options=bold>Orbital</> '.Release::display());
        $this->newLine();

        $this->twoColumn('Version', Release::version());
        $this->twoColumn('Commit', Release::commit() ?? 'not recorded (source checkout)');
        $this->twoColumn('Channel', Release::channel());
        $this->twoColumn('Environment', (string) app()->environment());
        $this->twoColumn('PHP', PHP_VERSION);
        $this->twoColumn('Laravel', app()->version());

        $this->newLine();
        $this->twoColumn('License', Release::licenseSpdx().' — '.Release::licenseName());
        $this->twoColumn('Source', Release::sourceUrlForCommit());
        $this->twoColumn('Support', $support->statusLabel());

        $this->newLine();
        $this->line('  Orbital is free software. Self-hosting is free forever with no');
        $this->line('  feature gates and no seat limits. See LICENSING.md.');

        if (Release::sourceUrl() === 'https://github.com/calltheory/orbital') {
            $this->newLine();
            $this->line('  <comment>If you have modified Orbital, set ORBITAL_SOURCE_URL to your own</>');
            $this->line('  <comment>repository so your users\' AGPL section 13 offer resolves to the</>');
            $this->line('  <comment>source they are actually running.</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function twoColumn(string $label, string $value): void
    {
        $this->line(sprintf('  <fg=gray>%-14s</> %s', $label, $value));
    }
}
