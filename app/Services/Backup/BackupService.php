<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Services\Settings\PlatformSettingsRepository;
use App\Support\Release;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Takes an encrypted database backup and puts it somewhere else.
 *
 * The order of operations is the design. Dump, then encrypt, then
 * upload, THEN prune — a failure at any earlier step leaves the
 * previous good archives untouched. Pruning first (or pruning on a
 * schedule of its own) is how people discover during a restore that a
 * month of failing backups quietly aged out the last working one.
 *
 * Scope is the database only. Object storage content — recordings, MMS
 * media, mail attachments — is not copied here; see
 * docs/admin/backups.md for why and what to do about it. The database
 * is the part that cannot be reconstructed from anything else.
 */
class BackupService
{
    /**
     * Keys recording the last successful run.
     *
     * In platform_settings rather than a table of their own: two values
     * do not earn a migration, and this store is already durable and
     * already read by the metrics collector. When the backup history UI
     * lands it will want a real `backup_runs` table and these move
     * there.
     */
    public const LAST_SUCCESS_KEY = 'backup.last_success_at';

    public const LAST_BYTES_KEY = 'backup.last_bytes';

    public function __construct(
        private readonly ArchiveCipher $cipher,
        private readonly PlatformSettingsRepository $settings,
    ) {}

    /**
     * @param  string|null  $localPath  write here instead of uploading
     * @return array<string, mixed> the manifest
     */
    public function run(?string $localPath = null, bool $prune = true): array
    {
        $startedAt = microtime(true);
        $now = CarbonImmutable::now();
        $name = 'orbital-'.$now->format('Y-m-d-His');

        $dump = $this->tempFile($name.'.dump');
        $archive = $this->tempFile($name.'.dump.enc');

        try {
            $plainBytes = $this->dumpDatabase($dump);
            $encryptedBytes = $this->encryptArchive($dump, $archive);

            // The dump is deleted the moment it is no longer needed.
            // An unencrypted copy of every caller record sitting in
            // /tmp is exactly what the encryption is meant to prevent.
            $this->shred($dump);

            $manifest = $this->manifest($name, $now, $plainBytes, $encryptedBytes, $archive, $startedAt);

            if ($localPath !== null) {
                $this->moveTo($archive, $localPath);
                $manifest['location'] = $localPath;
            } else {
                $manifest['location'] = $this->upload($name, $archive, $manifest);
            }

            $this->recordSuccess($now, $encryptedBytes);

            // Only ever after the new archive is safely stored.
            if ($localPath === null && $prune) {
                $manifest['pruned'] = $this->prune();
            }

            return $manifest;
        } finally {
            $this->shred($dump);
            $this->shred($archive);
        }
    }

    /**
     * Restore a dump file into the configured database.
     *
     * Deliberately takes an already-decrypted dump: decryption is a
     * separate, explicit step in the command so an operator can inspect
     * or relocate the dump before letting anything write to a database.
     */
    public function restore(string $dumpPath, bool $clean): void
    {
        $config = $this->connection();
        $binary = (string) config('backup.pg_restore_path', 'pg_restore');

        $args = [
            $binary,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--username='.$config['username'],
            '--dbname='.$config['database'],
            '--no-owner',
            '--no-privileges',
            // Keep going past benign "already exists" noise; real
            // failures still surface in the exit code.
            '--exit-on-error',
        ];

        if ($clean) {
            $args[] = '--clean';
            $args[] = '--if-exists';
        }

        $args[] = $dumpPath;

        $this->runProcess($args, $config['password'], 'pg_restore');
    }

    /**
     * @return array<int, string> names deleted
     */
    public function prune(): array
    {
        $keepDays = (int) config('backup.keep_days', 30);

        if ($keepDays <= 0) {
            return [];
        }

        $disk = Storage::disk($this->destinationDisk());
        $prefix = $this->prefix();
        $cutoff = CarbonImmutable::now()->subDays($keepDays);
        $deleted = [];

        foreach ($disk->files($prefix) as $file) {
            $taken = $this->timestampFromName(basename($file));

            // A file whose name we cannot parse is never deleted. It is
            // somebody else's object in our prefix, or a naming change
            // we have not accounted for, and neither is a reason to
            // destroy data.
            if ($taken === null || $taken->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            $disk->delete($file);
            $deleted[] = basename($file);
        }

        return $deleted;
    }

    /**
     * @return array<int, array{name: string, bytes: int, taken_at: ?string}>
     */
    public function listArchives(): array
    {
        $disk = Storage::disk($this->destinationDisk());
        $out = [];

        foreach ($disk->files($this->prefix()) as $file) {
            if (! str_ends_with($file, '.dump.enc')) {
                continue;
            }

            $taken = $this->timestampFromName(basename($file));

            $out[] = [
                'name' => basename($file),
                'bytes' => $disk->size($file),
                'taken_at' => $taken?->toIso8601String(),
            ];
        }

        usort($out, fn (array $a, array $b): int => strcmp((string) $b['name'], (string) $a['name']));

        return $out;
    }

    public function download(string $name, string $localPath): void
    {
        $disk = Storage::disk($this->destinationDisk());
        $remote = $this->prefix().'/'.$name;

        if (! $disk->exists($remote)) {
            throw new RuntimeException("backup: {$name} not found in the destination");
        }

        $source = $disk->readStream($remote);
        $target = fopen($localPath, 'wb');

        if ($source === false || $target === false) {
            throw new RuntimeException('backup: could not open the archive for download');
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
    }

    public function decrypt(string $archivePath, string $dumpPath, string $passphrase): int
    {
        $source = fopen($archivePath, 'rb');
        $target = fopen($dumpPath, 'wb');

        if ($source === false || $target === false) {
            throw new RuntimeException('backup: could not open files for decryption');
        }

        try {
            return $this->cipher->decrypt($source, $target, $passphrase);
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    public function passphrase(): string
    {
        $key = (string) config('backup.encryption_key', '');

        if (trim($key) === '') {
            throw new RuntimeException(
                'backup: BACKUP_ENCRYPTION_KEY is not set. Backups are never written unencrypted — '
                .'generate one with `php artisan orbital:backup --generate-key` and store it somewhere '
                .'that is NOT this platform.'
            );
        }

        return $key;
    }

    // ── Internals ───────────────────────────────────────────────────

    private function dumpDatabase(string $target): int
    {
        $config = $this->connection();
        $binary = (string) config('backup.pg_dump_path', 'pg_dump');

        $this->runProcess([
            $binary,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--username='.$config['username'],
            '--dbname='.$config['database'],
            // Custom format: compressed, and pg_restore can pull single
            // tables out of it. Plain SQL is easier to read and much
            // worse to restore selectively from at 3am.
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file='.$target,
        ], $config['password'], 'pg_dump');

        $bytes = @filesize($target);

        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('backup: pg_dump produced an empty file');
        }

        return $bytes;
    }

    private function encryptArchive(string $dump, string $archive): int
    {
        $source = fopen($dump, 'rb');
        $target = fopen($archive, 'wb');

        if ($source === false || $target === false) {
            throw new RuntimeException('backup: could not open files for encryption');
        }

        try {
            return $this->cipher->encrypt($source, $target, $this->passphrase());
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(
        string $name,
        CarbonImmutable $now,
        int $plainBytes,
        int $encryptedBytes,
        string $archive,
        float $startedAt,
    ): array {
        return [
            'name' => $name,
            'created_at' => $now->toIso8601String(),
            'orbital_version' => Release::version(),
            'orbital_commit' => Release::shortCommit(),
            'database' => $this->connection()['database'],
            'plain_bytes' => $plainBytes,
            'encrypted_bytes' => $encryptedBytes,
            'sha256' => hash_file('sha256', $archive),
            'duration_seconds' => round(microtime(true) - $startedAt, 2),

            // The restore trap nobody expects. Encrypted columns —
            // platform_settings values, two-factor secrets, API tokens —
            // are encrypted with APP_KEY. Restoring this dump into an
            // install with a DIFFERENT key leaves those columns as
            // undecryptable noise while everything else looks perfect.
            // orbital:restore compares this and warns before writing.
            'app_key_fingerprint' => $this->appKeyFingerprint(),
        ];
    }

    private function appKeyFingerprint(): string
    {
        return substr(hash('sha256', (string) config('app.key')), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function upload(string $name, string $archive, array $manifest): string
    {
        $disk = Storage::disk($this->destinationDisk());
        $prefix = $this->prefix();

        $stream = fopen($archive, 'rb');

        if ($stream === false) {
            throw new RuntimeException('backup: could not open the archive for upload');
        }

        try {
            // Streamed, not read into memory — these get large.
            $disk->writeStream($prefix.'/'.$name.'.dump.enc', $stream);
        } finally {
            fclose($stream);
        }

        // The manifest is stored in the clear on purpose: it holds
        // sizes, versions and a hash, never data, and being able to see
        // what a backup IS without holding the passphrase is the point.
        $disk->put(
            $prefix.'/'.$name.'.manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return $prefix.'/'.$name.'.dump.enc';
    }

    private function recordSuccess(CarbonImmutable $now, int $bytes): void
    {
        $this->settings->set(self::LAST_SUCCESS_KEY, $now->toIso8601String());
        $this->settings->set(self::LAST_BYTES_KEY, $bytes);
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function connection(): array
    {
        $name = (string) config('database.default');
        $config = (array) config("database.connections.{$name}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException(
                "backup: only PostgreSQL is supported, but the default connection '{$name}' is "
                .(string) ($config['driver'] ?? 'unknown')
            );
        }

        return [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (string) ($config['port'] ?? '5432'),
            'database' => (string) ($config['database'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ];
    }

    /**
     * @param  array<int, string>  $args
     */
    private function runProcess(array $args, string $password, string $label): void
    {
        $process = new Process($args, null, [
            // Via the environment, never on the command line, where it
            // would be visible in `ps` to every process on the host.
            'PGPASSWORD' => $password,
        ]);

        $process->setTimeout((float) config('backup.timeout', 3600));
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            if (str_contains($stderr, 'command not found') || $process->getExitCode() === 127) {
                throw new RuntimeException(
                    "backup: {$label} is not installed in this image. Backups need the PostgreSQL "
                    .'client tools; set BACKUP_PG_DUMP_PATH if they live somewhere unusual.'
                );
            }

            throw new RuntimeException("backup: {$label} failed — ".($stderr ?: 'no error output'));
        }
    }

    private function timestampFromName(string $name): ?CarbonImmutable
    {
        if (! preg_match('/^orbital-(\d{4}-\d{2}-\d{2})-(\d{6})\./', $name, $m)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d His', $m[1].' '.$m[2]) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function destinationDisk(): string
    {
        return (string) config('backup.destination', 'backups');
    }

    private function prefix(): string
    {
        return trim((string) config('backup.prefix', 'orbital-backups'), '/');
    }

    private function tempFile(string $name): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/'.$name;
    }

    private function moveTo(string $from, string $to): void
    {
        if (! @rename($from, $to) && ! @copy($from, $to)) {
            throw new RuntimeException("backup: could not write to {$to}");
        }
    }

    private function shred(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
