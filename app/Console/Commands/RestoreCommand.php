<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restore an encrypted backup archive into the database.
 *
 * CLI-first, and deliberately not in the admin UI: the moment you need
 * this is the moment the admin UI is most likely to be the thing that
 * is broken. It also runs in stages — download, decrypt, verify, then
 * restore — so an operator can stop after any of them, inspect the
 * dump, or move it somewhere else before anything writes to a database.
 *
 * This is destructive by nature. --clean drops existing objects first,
 * and the confirmation is not skippable without --force, because the
 * distance between "restore last night's backup" and "wipe production"
 * is one wrong --dbname.
 *
 *   php artisan orbital:restore --list
 *   php artisan orbital:restore orbital-2026-09-04-020000.dump.enc --decrypt-only
 *   php artisan orbital:restore orbital-2026-09-04-020000.dump.enc --clean
 *   php artisan orbital:restore --file=/tmp/local.dump.enc
 */
class RestoreCommand extends Command
{
    protected $signature = 'orbital:restore
        {archive? : Archive name in the destination. Omit when using --file.}
        {--file= : Restore from a local archive instead of the destination.}
        {--clean : Drop existing objects before restoring. Required to overwrite a populated database.}
        {--decrypt-only : Decrypt to a dump file and stop, without touching the database.}
        {--output= : Where to write the decrypted dump (default: a temp file).}
        {--list : List archives in the destination and exit.}
        {--force : Skip the confirmation prompt. For non-interactive runs.}';

    protected $description = 'Restore the database from an encrypted Orbital backup archive.';

    public function handle(BackupService $backups): int
    {
        if ($this->option('list')) {
            $this->call('orbital:backup', ['--list' => true]);

            return self::SUCCESS;
        }

        $local = $this->option('file');
        $name = $this->argument('archive');

        if (! $local && ! $name) {
            $this->error('Give an archive name, or --file for a local archive. --list shows what is available.');

            return self::FAILURE;
        }

        try {
            $passphrase = $backups->passphrase();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $archivePath = $local ?: $this->fetch($backups, (string) $name);

        if ($archivePath === null) {
            return self::FAILURE;
        }

        $dumpPath = $this->option('output')
            ?: rtrim(sys_get_temp_dir(), '/').'/orbital-restore-'.getmypid().'.dump';

        $this->info('Decrypting...');

        try {
            $bytes = $backups->decrypt($archivePath, $dumpPath, $passphrase);
        } catch (Throwable $e) {
            // The cipher is authenticated, so this catches a wrong
            // passphrase, a corrupted download, and a truncated upload
            // alike — all before anything touches the database.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('  Decrypted '.$bytes.' bytes to '.$dumpPath);

        if ($this->option('decrypt-only')) {
            $this->newLine();
            $this->info('Stopped after decryption as asked. Restore it yourself with:');
            $this->line('  pg_restore --host=... --dbname=... --no-owner --no-privileges '.$dumpPath);
            $this->warn('That file is an UNENCRYPTED copy of every record. Delete it when you are done.');

            return self::SUCCESS;
        }

        if (! $this->confirmDestruction()) {
            $this->warn('Aborted. The decrypted dump is still at '.$dumpPath);

            return self::FAILURE;
        }

        $this->info('Restoring...');

        try {
            $backups->restore($dumpPath, clean: (bool) $this->option('clean'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->warn('The decrypted dump is still at '.$dumpPath.' — the restore can be retried.');

            return self::FAILURE;
        }

        @unlink($dumpPath);

        $this->newLine();
        $this->info('Restore complete.');
        $this->comment('Now: restart Horizon and the agent worker so they pick up the restored config,');
        $this->comment('and run `php artisan orbital:generate-config` to rebuild Asterisk from the DB.');

        return self::SUCCESS;
    }

    private function fetch(BackupService $backups, string $name): ?string
    {
        $path = rtrim(sys_get_temp_dir(), '/').'/'.basename($name);

        $this->info('Downloading '.$name.'...');

        try {
            $backups->download($name, $path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return null;
        }

        return $path;
    }

    /**
     * The last thing standing between a tired operator and an
     * irreversible mistake.
     */
    private function confirmDestruction(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $config = (array) config('database.connections.'.config('database.default'));
        $target = ($config['host'] ?? '?').'/'.($config['database'] ?? '?');

        $this->newLine();
        $this->warn('About to restore into: '.$target);

        if ($this->option('clean')) {
            $this->warn('--clean is set: existing tables will be DROPPED first. This is not reversible.');
        }

        return $this->confirm('Restore into '.$target.'?', false);
    }
}
