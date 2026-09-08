<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Encrypted database backup to an off-box destination.
 *
 * HA survives a dead node. Replication survives a dead disk. Neither
 * survives a dropped table, a bad migration, or a compromised account —
 * those replicate to every copy instantly. This is the only one of the
 * three that recovers from a mistake.
 *
 * Exits non-zero on failure so the scheduler marks it failed and
 * `orbital_backup_age_seconds` goes stale, which is what the
 * OrbitalBackupStale alert watches. A backup system that fails silently
 * is worse than none, because it is believed.
 *
 *   php artisan orbital:backup
 *   php artisan orbital:backup --path=/tmp/orbital.dump.enc   # local, no upload
 *   php artisan orbital:backup --list
 *   php artisan orbital:backup --generate-key
 */
class BackupCommand extends Command
{
    protected $signature = 'orbital:backup
        {--path= : Write the encrypted archive here instead of uploading it.}
        {--no-prune : Keep old archives regardless of the retention setting.}
        {--list : List archives already in the destination and exit.}
        {--generate-key : Print a strong BACKUP_ENCRYPTION_KEY and exit.}';

    protected $description = 'Take an encrypted PostgreSQL backup and upload it to the configured destination.';

    public function handle(BackupService $backups): int
    {
        if ($this->option('generate-key')) {
            return $this->generateKey();
        }

        if ($this->option('list')) {
            return $this->listArchives($backups);
        }

        // Fail before doing any work rather than after producing a dump
        // there is no way to encrypt.
        try {
            $backups->passphrase();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backing up the database...');

        try {
            $manifest = $backups->run(
                localPath: $this->option('path') ?: null,
                prune: ! $this->option('no-prune'),
            );
        } catch (Throwable $e) {
            // Logged as well as printed: the scheduled run has no
            // terminal, and this is the message somebody needs when
            // they finally look at why the alert fired.
            Log::error('backup failed', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Archive:    '.$manifest['location']);
        $this->line('  Dump size:  '.$this->humanBytes((int) $manifest['plain_bytes']));
        $this->line('  Encrypted:  '.$this->humanBytes((int) $manifest['encrypted_bytes']));
        $this->line('  SHA-256:    '.substr((string) $manifest['sha256'], 0, 32).'...');
        $this->line('  Took:       '.$manifest['duration_seconds'].'s');

        if (! empty($manifest['pruned'])) {
            $this->line('  Pruned:     '.count((array) $manifest['pruned']).' archive(s) past retention');
        }

        $this->newLine();
        $this->info('Backup complete.');

        // Said every time on purpose. The failure mode this warns about
        // is silent for months and then total.
        $this->comment('Restore needs BACKUP_ENCRYPTION_KEY. Without it this archive is unrecoverable.');

        return self::SUCCESS;
    }

    private function generateKey(): int
    {
        $this->newLine();
        $this->line('BACKUP_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
        $this->newLine();
        $this->warn('Store this OUTSIDE Orbital — a password manager, or your infrastructure secret store.');
        $this->warn('Keeping it only in this platform means losing it in exactly the disaster it exists for.');
        $this->newLine();
        $this->comment('Changing this key does not re-encrypt existing archives. Keep the old one as long');
        $this->comment('as you keep archives taken with it.');

        return self::SUCCESS;
    }

    private function listArchives(BackupService $backups): int
    {
        $archives = $backups->listArchives();

        if ($archives === []) {
            $this->warn('No archives in the destination.');
            $this->line('Destination: '.config('backup.destination').' / '.config('backup.prefix'));

            return self::SUCCESS;
        }

        $this->table(
            ['Archive', 'Size', 'Taken'],
            array_map(fn (array $a): array => [
                $a['name'],
                $this->humanBytes((int) $a['bytes']),
                $a['taken_at'] ?? 'unknown',
            ], $archives),
        );

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
