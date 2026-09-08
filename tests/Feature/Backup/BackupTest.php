<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Models\PlatformSetting;
use App\Services\Backup\ArchiveCipher;
use App\Services\Backup\BackupService;
use App\Services\Metrics\PlatformMetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Encrypted database backups.
 *
 * HA survives a dead node and replication survives a dead disk. Neither
 * survives a dropped table, a bad migration, or a compromised account —
 * those replicate to every copy instantly and faithfully. This is the
 * only mechanism that recovers from a mistake, which makes the failure
 * modes below the ones worth pinning:
 *
 *   - An archive that decrypts to silent garbage.
 *   - A truncated upload that looks complete.
 *   - Retention deleting the last good archive.
 *   - A backup that stops running and says nothing.
 *
 * NOT covered here: pg_dump and pg_restore themselves. The suite runs
 * on SQLite and those need a live PostgreSQL, so the dump/restore path
 * is exercised by hand against a real database. Everything on either
 * side of it is tested.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSPHRASE = 'correct horse battery staple';

    // ── The cipher ──────────────────────────────────────────────────

    public function test_an_archive_round_trips_byte_for_byte(): void
    {
        // Larger than the 1 MiB chunk so the multi-chunk path runs; a
        // single-chunk test would miss the streaming logic entirely.
        $plain = random_bytes(2 * 1048576 + 7919);
        $decrypted = $this->roundTrip($plain, self::PASSPHRASE, self::PASSPHRASE);

        $this->assertSame($plain, $decrypted);
    }

    public function test_a_wrong_passphrase_is_rejected_rather_than_producing_garbage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wrong passphrase|corrupt/i');

        $this->roundTrip('some database bytes', self::PASSPHRASE, 'not the passphrase');
    }

    /**
     * The failure this catches is an upload that died halfway. The
     * archive is valid up to the cut, so a naive format would decrypt
     * cleanly and restore a partial database over a working one.
     */
    public function test_a_truncated_archive_is_detected(): void
    {
        $archive = $this->encryptToFile(random_bytes(3 * 1048576));
        file_put_contents($archive, substr((string) file_get_contents($archive), 0, 900000));

        $this->expectException(RuntimeException::class);

        app(BackupService::class)->decrypt($archive, $this->tempPath('out'), self::PASSPHRASE);
    }

    public function test_a_single_flipped_bit_is_detected(): void
    {
        $archive = $this->encryptToFile(random_bytes(1048576 * 2));
        $bytes = (string) file_get_contents($archive);
        $at = (int) (strlen($bytes) / 2);
        $bytes[$at] = chr(ord($bytes[$at]) ^ 0x01);
        file_put_contents($archive, $bytes);

        $this->expectException(RuntimeException::class);

        app(BackupService::class)->decrypt($archive, $this->tempPath('out'), self::PASSPHRASE);
    }

    public function test_a_file_that_is_not_an_orbital_archive_is_rejected_clearly(): void
    {
        $notAnArchive = $this->tempPath('random');
        file_put_contents($notAnArchive, 'this is just a text file');

        $this->expectExceptionMessageMatches('/not an Orbital backup archive/');

        app(BackupService::class)->decrypt($notAnArchive, $this->tempPath('out'), self::PASSPHRASE);
    }

    // ── Refusing to run unsafely ────────────────────────────────────

    /**
     * A dump holds every caller name, number, and message body on the
     * platform. There is no path that writes one unencrypted.
     */
    public function test_it_refuses_to_run_without_an_encryption_key(): void
    {
        config(['backup.encryption_key' => null]);

        $this->artisan('orbital:backup')
            ->expectsOutputToContain('BACKUP_ENCRYPTION_KEY is not set')
            ->assertFailed();
    }

    public function test_generate_key_emits_a_usable_key_and_a_warning(): void
    {
        $this->artisan('orbital:backup', ['--generate-key' => true])
            ->expectsOutputToContain('BACKUP_ENCRYPTION_KEY=')
            ->expectsOutputToContain('Store this OUTSIDE Orbital')
            ->assertSuccessful();
    }

    // ── Retention ───────────────────────────────────────────────────

    public function test_retention_deletes_old_archives_and_keeps_recent_ones(): void
    {
        Storage::fake('backups');
        config(['backup.destination' => 'backups', 'backup.keep_days' => 30]);

        $old = 'orbital-backups/orbital-2020-01-01-020000.dump.enc';
        $recent = 'orbital-backups/orbital-'.now()->format('Y-m-d-His').'.dump.enc';

        Storage::disk('backups')->put($old, 'x');
        Storage::disk('backups')->put($recent, 'x');

        $deleted = app(BackupService::class)->prune();

        $this->assertContains(basename($old), $deleted);
        Storage::disk('backups')->assertMissing($old);
        Storage::disk('backups')->assertExists($recent);
    }

    /**
     * A file whose name we cannot parse belongs to somebody else, or
     * predates a naming change. Neither is a reason to delete data from
     * the one place that survives a mistake.
     */
    public function test_retention_never_deletes_a_file_it_cannot_date(): void
    {
        Storage::fake('backups');
        config(['backup.destination' => 'backups', 'backup.keep_days' => 1]);

        Storage::disk('backups')->put('orbital-backups/something-else.tar.gz', 'x');
        Storage::disk('backups')->put('orbital-backups/README.txt', 'x');

        $this->assertSame([], app(BackupService::class)->prune());
        Storage::disk('backups')->assertExists('orbital-backups/something-else.tar.gz');
        Storage::disk('backups')->assertExists('orbital-backups/README.txt');
    }

    public function test_retention_of_zero_keeps_everything(): void
    {
        Storage::fake('backups');
        config(['backup.destination' => 'backups', 'backup.keep_days' => 0]);

        Storage::disk('backups')->put('orbital-backups/orbital-2001-01-01-020000.dump.enc', 'x');

        $this->assertSame([], app(BackupService::class)->prune());
        Storage::disk('backups')->assertExists('orbital-backups/orbital-2001-01-01-020000.dump.enc');
    }

    public function test_archives_are_listed_newest_first(): void
    {
        Storage::fake('backups');
        config(['backup.destination' => 'backups']);

        foreach (['2026-01-01-020000', '2026-03-01-020000', '2026-02-01-020000'] as $stamp) {
            Storage::disk('backups')->put("orbital-backups/orbital-{$stamp}.dump.enc", 'x');
            Storage::disk('backups')->put("orbital-backups/orbital-{$stamp}.manifest.json", '{}');
        }

        $names = array_column(app(BackupService::class)->listArchives(), 'name');

        // Manifests are not archives; listing them as restore
        // candidates would offer an operator a file that cannot restore.
        $this->assertCount(3, $names);
        $this->assertSame('orbital-2026-03-01-020000.dump.enc', $names[0]);
        $this->assertSame('orbital-2026-01-01-020000.dump.enc', $names[2]);
    }

    // ── Telling you when it stops ───────────────────────────────────

    /**
     * The whole point of the metric. A backup system that stops has no
     * symptom other than silence, so the last-success timestamp is what
     * the OrbitalBackupStale alert watches.
     */
    public function test_the_last_success_is_exposed_as_a_metric(): void
    {
        config(['backup.enabled' => true]);

        $at = now()->subHours(3);
        PlatformSetting::create(['key' => BackupService::LAST_SUCCESS_KEY, 'value' => $at->toIso8601String()]);
        PlatformSetting::create(['key' => BackupService::LAST_BYTES_KEY, 'value' => 12345]);

        $output = app(PlatformMetricsCollector::class)->collect()->render();

        $this->assertStringContainsString('orbital_backup_enabled 1', $output);
        $this->assertStringContainsString('orbital_backup_last_success_timestamp_seconds '.$at->getTimestamp(), $output);
        $this->assertStringContainsString('orbital_backup_last_bytes 12345', $output);
    }

    /**
     * "Never backed up" must be an explicit zero, not a missing series.
     * A dashboard cannot tell an absent metric from a broken metrics
     * pipeline, and those need different responses.
     */
    public function test_never_having_backed_up_reports_zero_rather_than_nothing(): void
    {
        $output = app(PlatformMetricsCollector::class)->collect()->render();

        $this->assertStringContainsString('orbital_backup_last_success_timestamp_seconds 0', $output);
        $this->assertStringContainsString('orbital_backup_enabled 0', $output);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        config(['backup.encryption_key' => self::PASSPHRASE]);
    }

    private function roundTrip(string $plain, string $encryptWith, string $decryptWith): string
    {
        $cipher = new ArchiveCipher;

        $plainFile = $this->tempPath('plain');
        $archive = $this->tempPath('enc');
        $out = $this->tempPath('dec');

        file_put_contents($plainFile, $plain);

        $in = fopen($plainFile, 'rb');
        $enc = fopen($archive, 'wb');
        $cipher->encrypt($in, $enc, $encryptWith);
        fclose($in);
        fclose($enc);

        $enc = fopen($archive, 'rb');
        $dec = fopen($out, 'wb');

        try {
            $cipher->decrypt($enc, $dec, $decryptWith);
        } finally {
            fclose($enc);
            fclose($dec);
        }

        return (string) file_get_contents($out);
    }

    private function encryptToFile(string $plain): string
    {
        $cipher = new ArchiveCipher;
        $plainFile = $this->tempPath('plain');
        $archive = $this->tempPath('enc');

        file_put_contents($plainFile, $plain);

        $in = fopen($plainFile, 'rb');
        $out = fopen($archive, 'wb');
        $cipher->encrypt($in, $out, self::PASSPHRASE);
        fclose($in);
        fclose($out);

        return $archive;
    }

    private function tempPath(string $label): string
    {
        $path = sys_get_temp_dir().'/orbital-backup-test-'.$label.'-'.uniqid();
        $this->beforeApplicationDestroyed(function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $path;
    }
}
