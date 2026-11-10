<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Filament\Pages\PlatformSettings;
use App\Jobs\RenderAiFallbackPromptJob;
use App\Models\AgentPersona;
use App\Models\Extension;
use App\Models\Team;
use App\Services\Telephony\AiFallbackPrompt;
use App\Services\Telephony\AsteriskConfigService;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * When the AI can't take or carry on a call, the caller hears a message
 * (rendered ahead of time, or Asterisk's stock prompts) instead of silence.
 */
class AiFallbackTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-prompts');
        Storage::fake('asterisk-config');
        config([
            'services.openai.api_key' => 'sk-test',
            'telephony.ai_fallback.message' => '',
            'telephony.ai_fallback.voice_provider' => 'openai',
            'telephony.ai_fallback.voice_id' => '',
        ]);
    }

    /** 2400 samples of 24 kHz PCM: 100 ms. */
    private static function pcm24k(): string
    {
        return str_repeat(pack('v', 3000), 2400);
    }

    public function test_the_message_is_rendered_as_8khz_signed_linear_and_only_when_it_changes(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(self::pcm24k())]);
        $prompt = app(AiFallbackPrompt::class);

        $this->assertSame('rendered', $prompt->ensureRendered());
        $audio = Storage::disk('asterisk-prompts')->get('ai-fallback/unavailable.sln');
        $this->assertSame(800 * 2, strlen($audio), '24 kHz → 8 kHz is a third of the samples');
        Http::assertSent(fn (Request $r) => $r['response_format'] === 'pcm'
            && $r['voice'] === 'alloy'
            && $r['input'] === AiFallbackPrompt::DEFAULT_MESSAGE);

        $this->assertSame('current', $prompt->ensureRendered());
        Http::assertSentCount(1);

        config(['telephony.ai_fallback.message' => 'Sorry, our lines are having trouble. Goodbye.']);
        $this->assertSame('rendered', $prompt->ensureRendered());
        Http::assertSentCount(2);
    }

    public function test_elevenlabs_audio_is_halved_from_16khz(): void
    {
        config(['telephony.ai_fallback.voice_provider' => 'elevenlabs', 'services.elevenlabs.api_key' => 'el-test']);
        Http::fake(['api.elevenlabs.io/*' => Http::response(str_repeat(pack('v', 100), 1600))]);

        $this->assertSame('rendered', app(AiFallbackPrompt::class)->ensureRendered());
        $this->assertSame(800 * 2, strlen(Storage::disk('asterisk-prompts')->get('ai-fallback/unavailable.sln')));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'output_format=pcm_16000'));
    }

    public function test_without_a_key_nothing_is_written_so_asterisk_keeps_its_stock_prompts(): void
    {
        config(['services.openai.api_key' => '']);
        Http::fake();

        $this->assertSame('failed', app(AiFallbackPrompt::class)->ensureRendered());
        $this->assertFalse(app(AiFallbackPrompt::class)->exists());
        Http::assertNothingSent();
    }

    public function test_downsampling_averages_signed_samples(): void
    {
        $pcm = pack('v*', 30, 60, 90, 0xFFFF - 29, 0xFFFF - 59, 0xFFFF - 89);  // 30,60,90,-30,-60,-90

        $out = array_values(unpack('v*', AiFallbackPrompt::downsample($pcm, 3)));

        $this->assertSame([60, 0x10000 - 60], $out);
    }

    public function test_the_command_reports_and_fails_when_it_cannot_render(): void
    {
        config(['services.openai.api_key' => '']);

        $this->artisan('orbital:render-ai-fallback')->expectsOutputToContain('stock prompts')->assertFailed();
    }

    public function test_the_dialplan_plays_the_message_when_the_ai_is_unavailable(): void
    {
        $dispatcher = app(AsteriskConfigService::class)->generateFromTrunkDispatcher();

        // The worker REFERs callers here.
        $this->assertStringContainsString('exten => ai-unavailable,1,Goto(orbital-ai-unavailable,s,1)', $dispatcher);
        $this->assertStringContainsString('[orbital-ai-unavailable]', $dispatcher);
        // The rendered message if it exists, else stock prompts.
        $this->assertStringContainsString('STAT(e,'.AiFallbackPrompt::ASTERISK_PATH.'.sln)', $dispatcher);
        $this->assertStringContainsString('Playback('.AiFallbackPrompt::ASTERISK_PATH.')', $dispatcher);
        $this->assertStringContainsString('Playback(sorry&please-try-call-later&goodbye)', $dispatcher);
    }

    public function test_a_dial_to_the_ai_that_does_not_connect_falls_through_to_the_message(): void
    {
        $team = Team::factory()->create(['personal_team' => false]);
        $persona = AgentPersona::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Steph', 'role' => 'receptionist']);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $team->id, 'number' => '1001', 'type' => 'ai_agent', 'is_active' => true,
            'assignable_type' => AgentPersona::class, 'assignable_id' => $persona->id,
        ]);

        $dialplan = app(AsteriskConfigService::class)->generateDialplanForTenant($team->id);

        $this->assertMatchesRegularExpression(
            '/Dial\(PJSIP\/1001@livekit[^\n]*\n same => n,GotoIf\(\$\["\$\{DIALSTATUS\}" != "ANSWER"\]\?orbital-ai-unavailable,s,1\)/',
            $dialplan,
        );
    }

    public function test_saving_platform_settings_queues_a_render(): void
    {
        Bus::fake([RenderAiFallbackPromptJob::class]);
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeUserWithTeamlessRole('super_admin'));

        Livewire::test(PlatformSettings::class)
            ->fillForm(['telephony__ai_fallback__message' => 'Sorry, please call back later. Goodbye.'])
            ->call('save')
            ->assertHasNoFormErrors();

        Bus::assertDispatched(RenderAiFallbackPromptJob::class);
    }
}
