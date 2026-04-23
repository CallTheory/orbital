<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\PruneExpiredCallRecordingsJob;
use App\Models\CallLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies the retention prune job:
 *   - skips rows whose anchor date is inside the window
 *   - deletes S3 objects + clears columns for rows past the window
 *   - honors a client-level retention override
 *   - treats retention_days <= 0 as "keep forever"
 *
 * Uses the `s3` disk faked to a local filesystem — the real S3 driver
 * isn't required for this behavior and a fake disk is deterministic.
 */
class PruneExpiredCallRecordingsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('telephony.recording.storage_disk', 's3');
        config()->set('telephony.recording.retention_days', 30);
        Storage::fake('s3');
    }

    public function test_it_deletes_expired_recordings_and_clears_columns(): void
    {
        $team = $this->makeTeam();
        $path = 'clients/'.$team->id.'/2026/01/old-mix.wav';
        Storage::disk('s3')->put($path, 'fake-wav');

        $call = $this->makeCall($team, [
            'recording_path' => $path,
            'recording_size_bytes' => 8,
            'started_at' => now()->subDays(60),
            'ended_at' => now()->subDays(60),
        ]);

        dispatch_sync(new PruneExpiredCallRecordingsJob());

        Storage::disk('s3')->assertMissing($path);
        $this->assertDatabaseHas('call_logs', [
            'id' => $call->id,
            'recording_path' => null,
            'recording_size_bytes' => null,
        ]);
    }

    public function test_it_keeps_recent_recordings(): void
    {
        $team = $this->makeTeam();
        $path = 'clients/'.$team->id.'/2026/04/recent-mix.wav';
        Storage::disk('s3')->put($path, 'fake-wav');

        $call = $this->makeCall($team, [
            'recording_path' => $path,
            'recording_size_bytes' => 8,
            'started_at' => now()->subDays(5),
            'ended_at' => now()->subDays(5),
        ]);

        dispatch_sync(new PruneExpiredCallRecordingsJob());

        Storage::disk('s3')->assertExists($path);
        $this->assertDatabaseHas('call_logs', [
            'id' => $call->id,
            'recording_path' => $path,
        ]);
    }

    public function test_tenant_retention_override_shortens_window(): void
    {
        $team = $this->makeTeam([
            'recording_overrides' => ['retention_days' => 7],
        ]);

        $path = 'clients/'.$team->id.'/2026/04/ten-days-old.wav';
        Storage::disk('s3')->put($path, 'fake-wav');

        $call = $this->makeCall($team, [
            'recording_path' => $path,
            'recording_size_bytes' => 8,
            'started_at' => now()->subDays(10),
            'ended_at' => now()->subDays(10),
        ]);

        dispatch_sync(new PruneExpiredCallRecordingsJob());

        Storage::disk('s3')->assertMissing($path);
        $this->assertDatabaseHas('call_logs', [
            'id' => $call->id,
            'recording_path' => null,
        ]);
    }

    public function test_zero_retention_keeps_recordings_forever(): void
    {
        config()->set('telephony.recording.retention_days', 0);

        $team = $this->makeTeam();
        $path = 'clients/'.$team->id.'/2026/01/ancient.wav';
        Storage::disk('s3')->put($path, 'fake-wav');

        $call = $this->makeCall($team, [
            'recording_path' => $path,
            'recording_size_bytes' => 8,
            'started_at' => now()->subYears(3),
            'ended_at' => now()->subYears(3),
        ]);

        dispatch_sync(new PruneExpiredCallRecordingsJob());

        Storage::disk('s3')->assertExists($path);
        $this->assertDatabaseHas('call_logs', [
            'id' => $call->id,
            'recording_path' => $path,
        ]);
    }

    protected function makeCall(Team $team, array $attrs = []): CallLog
    {
        return CallLog::create(array_merge([
            'team_id' => $team->id,
            'unique_id' => 'test-'.uniqid(),
            'direction' => 'inbound',
            'status' => 'completed',
            'from_number' => '+15555550100',
            'to_number' => '+15555550200',
        ], $attrs));
    }

    protected function makeTeam(array $attrs = []): Team
    {
        $owner = User::factory()->create();
        return Team::forceCreate(array_merge([
            'user_id' => $owner->id,
            'name' => 'Prune Test Client '.uniqid(),
            'personal_team' => false,
        ], $attrs));
    }
}
