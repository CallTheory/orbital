<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\Extension;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\CallRecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Exercises the extension → client → platform fallback chain. The
 * resolver is the linchpin for every recording decision: dialplan
 * emission, upload path, retention prune, and the Filament UI all
 * consume its output, so every branch here has to be covered.
 */
class CallRecordingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Extension observers dispatch RegenerateTelephonyConfig which
        // tries to write Asterisk files — not relevant to resolver tests.
        Bus::fake([RegenerateTelephonyConfig::class]);
        config()->set('telephony.recording.enabled', true);
        config()->set('telephony.recording.format', 'wav');
        config()->set('telephony.recording.retention_days', 90);
        config()->set('telephony.recording.storage_disk', 's3');
        config()->set('telephony.recording.beep_on_record', false);
    }

    public function test_platform_defaults_apply_when_team_has_no_overrides(): void
    {
        $team = $this->makeTeam();
        $policy = app(CallRecordingService::class)->resolveForTeam($team);

        $this->assertTrue($policy->enabled);
        $this->assertSame('wav', $policy->format);
        $this->assertSame(90, $policy->retentionDays);
        $this->assertSame('platform', $policy->source);
    }

    public function test_team_overrides_win_over_platform(): void
    {
        $team = $this->makeTeam([
            'recording_overrides' => [
                'enabled' => false,
                'retention_days' => 30,
                'format' => 'mp3',
            ],
        ]);

        $policy = app(CallRecordingService::class)->resolveForTeam($team);

        $this->assertFalse($policy->enabled);
        $this->assertSame('mp3', $policy->format);
        $this->assertSame(30, $policy->retentionDays);
        $this->assertSame('client', $policy->source);
    }

    public function test_extension_always_mode_forces_recording_on(): void
    {
        $team = $this->makeTeam([
            'recording_overrides' => ['enabled' => false],
        ]);

        $ext = Extension::create([
            'team_id' => $team->id,
            'number' => '201',
            'type' => 'sip_phone',
            'context' => 'internal',
            'is_active' => true,
            'recording_mode' => 'always',
        ]);

        $policy = app(CallRecordingService::class)->resolveForExtension($ext);

        $this->assertTrue($policy->enabled);
        $this->assertSame('extension', $policy->source);
    }

    public function test_extension_never_mode_forces_recording_off(): void
    {
        $team = $this->makeTeam();
        $ext = Extension::create([
            'team_id' => $team->id,
            'number' => '202',
            'type' => 'sip_phone',
            'context' => 'internal',
            'is_active' => true,
            'recording_mode' => 'never',
        ]);

        $policy = app(CallRecordingService::class)->resolveForExtension($ext);

        $this->assertFalse($policy->enabled);
        $this->assertSame('extension', $policy->source);
    }

    public function test_extension_inherit_uses_tenant_policy(): void
    {
        $team = $this->makeTeam([
            'recording_overrides' => ['retention_days' => 14],
        ]);
        $ext = Extension::create([
            'team_id' => $team->id,
            'number' => '203',
            'type' => 'sip_phone',
            'context' => 'internal',
            'is_active' => true,
            'recording_mode' => 'inherit',
        ]);

        $policy = app(CallRecordingService::class)->resolveForExtension($ext);

        $this->assertTrue($policy->enabled);
        $this->assertSame(14, $policy->retentionDays);
        $this->assertSame('client', $policy->source);
    }

    public function test_path_for_is_tenant_scoped(): void
    {
        $path = app(CallRecordingService::class)->pathFor(42, '1234567890.1', 'wav');

        $this->assertStringStartsWith('clients/42/', $path);
        $this->assertStringEndsWith('.wav', $path);
        $this->assertStringNotContainsString('..', $path);
    }

    protected function makeTeam(array $attrs = []): Team
    {
        $owner = User::factory()->create();

        return Team::forceCreate(array_merge([
            'user_id' => $owner->id,
            'name' => 'Resolver Test '.uniqid(),
            'personal_team' => false,
        ], $attrs));
    }
}
