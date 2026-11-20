<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\PlatformSettings;
use App\Models\PlatformSetting;
use App\Providers\RuntimeConfigOverrideProvider;
use App\Services\Settings\FrozenSettingsCleanup;
use App\Services\Settings\PlatformSettingsRepository;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Platform Settings shows only the fields the chosen options use, and
 * a save applies itself: queue workers restart on their own, and a
 * change that needs more (an Asterisk reload) opens the restart prompt.
 */
class PlatformSettingsPageTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Saving queues the AI-unavailable prompt render; keep it off the
        // real prompts path (unwritable in CI).
        Storage::fake('asterisk-prompts');
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeUserWithTeamlessRole('super_admin'));
    }

    public function test_mail_fields_follow_the_chosen_mailer(): void
    {
        Livewire::test(PlatformSettings::class)
            ->fillForm(['mail__default' => 'smtp'])
            ->assertFormFieldVisible('mail__smtp__host')
            ->assertFormFieldHidden('services__sendgrid__key')
            ->fillForm(['mail__default' => 'sendgrid'])
            ->assertFormFieldHidden('mail__smtp__host')
            ->assertFormFieldHidden('mail__smtp__password')
            ->assertFormFieldVisible('services__sendgrid__key')
            ->assertFormFieldVisible('mail__from__address');
    }

    public function test_switching_mailers_keeps_the_hidden_mailers_settings(): void
    {
        $repo = app(PlatformSettingsRepository::class);
        $repo->set('mail.smtp.host', 'smtp.example.com');

        Livewire::test(PlatformSettings::class)
            ->fillForm(['mail__default' => 'sendgrid', 'services__sendgrid__key' => 'SG.key'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sendgrid', $repo->get('mail.default'));
        $this->assertSame('SG.key', $repo->get('services.sendgrid.key'));
        $this->assertSame('smtp.example.com', $repo->get('mail.smtp.host'));
    }

    public function test_saving_a_change_restarts_the_queue_workers(): void
    {
        Cache::forget('illuminate:queue:restart');

        Livewire::test(PlatformSettings::class)
            ->fillForm(['mail__default' => 'sendgrid'])
            ->call('save')
            ->assertNotified('Settings saved')
            ->assertSet('mountedActions', []);

        $this->assertNotNull(Cache::get('illuminate:queue:restart'));
    }

    public function test_a_change_that_needs_asterisk_opens_the_restart_prompt(): void
    {
        $current = (bool) config('telephony.asterisk.internal_did_simulation');

        Livewire::test(PlatformSettings::class)
            ->fillForm(['telephony__asterisk__internal_did_simulation' => ! $current])
            ->call('save')
            ->assertActionMounted('restartServices');
    }

    public function test_a_setting_the_deployment_manages_is_read_only_and_never_saved(): void
    {
        config([
            'orbital.managed_settings' => ['services.inbound_mail.domain'],
            'services.inbound_mail.domain' => 'inbound.dallas.example.com',
        ]);
        $repo = app(PlatformSettingsRepository::class);
        $repo->set('services.inbound_mail.domain', 'stale.example.com');

        Livewire::test(PlatformSettings::class)
            ->assertFormFieldDisabled('services__inbound_mail__domain')
            ->assertFormSet(['services__inbound_mail__domain' => 'inbound.dallas.example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        // The save left the old row alone rather than writing a new value.
        $this->assertSame('stale.example.com', $repo->get('services.inbound_mail.domain'));
        $this->assertSame('inbound.dallas.example.com', config('services.inbound_mail.domain'));
    }

    public function test_a_save_stores_only_the_fields_that_changed(): void
    {
        Livewire::test(PlatformSettings::class)
            ->fillForm(['mail__from__address' => 'ops@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['mail.from.address'], PlatformSetting::query()->pluck('key')->all());
        $this->assertSame('ops@example.com', config('mail.from.address'));
    }

    public function test_untouched_fields_keep_following_the_deployment_after_a_save(): void
    {
        Livewire::test(PlatformSettings::class)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, PlatformSetting::query()->count());

        // The chart later points the app at the in-cluster service, and
        // nothing saved earlier gets in the way.
        config(['services.ollama.url' => 'http://orbital-orbital-ollama:11434']);
        $provider = new RuntimeConfigOverrideProvider(app());
        (new \ReflectionMethod($provider, 'applyOverrides'))->invoke($provider);

        $this->assertSame('http://orbital-orbital-ollama:11434', config('services.ollama.url'));
    }

    /**
     * Dallas, 2026-11-19: Tracing switched off with the chart setting it
     * on. PHP's loose `false != null` is false, so the save stored
     * nothing and every restarted worker came back with tracing on.
     */
    public function test_switching_off_a_setting_the_deployment_turns_on_is_saved(): void
    {
        config(['observability.tracing.enabled' => true, 'observability.tracing.sample_ratio' => 0.05]);
        $repo = app(PlatformSettingsRepository::class);

        Livewire::test(PlatformSettings::class)
            ->fillForm([
                'observability__tracing__enabled' => false,
                'observability__tracing__sample_ratio' => 0,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($repo->get('observability.tracing.enabled'));
        $this->assertEquals(0, $repo->get('observability.tracing.sample_ratio'));

        // A restarted worker boots with the saved value, not the chart's.
        config(['observability.tracing.enabled' => true]);
        $this->bootOverrides();
        $this->assertFalse(config('observability.tracing.enabled'));
    }

    public function test_switching_on_a_setting_the_deployment_leaves_unset_is_saved(): void
    {
        config(['telephony.asterisk.internal_did_simulation' => null]);
        $repo = app(PlatformSettingsRepository::class);

        Livewire::test(PlatformSettings::class)
            ->fillForm(['telephony__asterisk__internal_did_simulation' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($repo->get('telephony.asterisk.internal_did_simulation'));
    }

    public function test_setting_a_value_back_to_the_deployments_removes_the_saved_one(): void
    {
        $repo = app(PlatformSettingsRepository::class);
        $repo->set('mail.from.address', 'ops@example.com');
        $this->bootOverrides();

        Livewire::test(PlatformSettings::class)
            ->assertFormSet(['mail__from__address' => 'ops@example.com'])
            ->fillForm(['mail__from__address' => 'no-reply@orbital.local'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($repo->get('mail.from.address'));
        $this->assertSame('no-reply@orbital.local', config('mail.from.address'));
    }

    public function test_reset_to_default_is_offered_only_for_a_saved_value(): void
    {
        $reset = fn (string $field) => TestAction::make('resetToDefault')->schemaComponent($field);

        app(PlatformSettingsRepository::class)->set('mail.from.address', 'ops@example.com');
        $this->bootOverrides();

        Livewire::test(PlatformSettings::class)
            ->assertActionVisible($reset('mail__from__address'))
            ->assertActionDoesNotExist($reset('mail__from__name'));
    }

    public function test_reset_to_default_drops_the_saved_value_and_applies_the_deployments(): void
    {
        $repo = app(PlatformSettingsRepository::class);
        $repo->set('mail.from.address', 'ops@example.com');
        $this->bootOverrides();
        Cache::forget('illuminate:queue:restart');

        Livewire::test(PlatformSettings::class)
            ->callAction(TestAction::make('resetToDefault')->schemaComponent('mail__from__address'))
            ->assertNotified('Reset to default')
            ->assertFormSet(['mail__from__address' => 'no-reply@orbital.local']);

        $this->assertNull($repo->get('mail.from.address'));
        $this->assertSame('no-reply@orbital.local', config('mail.from.address'));
        $this->assertNotNull(Cache::get('illuminate:queue:restart'), 'workers pick up the change');
    }

    public function test_cleanup_removes_frozen_values_and_keeps_chosen_ones(): void
    {
        // Saved while the deployment still used the compose address;
        // the chart now sets the in-cluster one.
        config([
            'services.ollama.url' => 'http://orbital-orbital-ollama:11434',
            'observability.tracing.endpoint' => 'http://tempo.monitoring.svc.cluster.local:4318/v1/traces',
        ]);
        $repo = app(PlatformSettingsRepository::class);
        $repo->set('services.ollama.url', 'http://ollama:11434');
        $repo->set('observability.tracing.endpoint', 'http://tempo:4318/v1/traces');
        // Same as the deployment's value: frozen, harmless to drop.
        $repo->set('mail.from.address', 'no-reply@orbital.local');
        // Chosen on purpose: kept.
        $repo->set('mail.from.name', 'Apollo Answers');
        $this->bootOverrides();

        $removed = app(FrozenSettingsCleanup::class)->run();

        sort($removed);
        $this->assertSame(['mail.from.address', 'observability.tracing.endpoint', 'services.ollama.url'], $removed);
        $this->assertSame('Apollo Answers', $repo->get('mail.from.name'));
        $this->assertSame(['mail.from.name'], PlatformSetting::query()->pluck('key')->all());
    }

    private function bootOverrides(): void
    {
        $provider = new RuntimeConfigOverrideProvider(app());
        (new \ReflectionMethod($provider, 'applyOverrides'))->invoke($provider);
    }

    public function test_boot_ignores_a_saved_value_for_a_managed_setting(): void
    {
        app(PlatformSettingsRepository::class)->set('services.inbound_mail.domain', 'stale.example.com');
        app(PlatformSettingsRepository::class)->set('mail.from.address', 'ops@example.com');
        config([
            'orbital.managed_settings' => ['services.inbound_mail.domain'],
            'services.inbound_mail.domain' => 'inbound.dallas.example.com',
        ]);

        $provider = new RuntimeConfigOverrideProvider(app());
        (new \ReflectionMethod($provider, 'applyOverrides'))->invoke($provider);

        $this->assertSame('inbound.dallas.example.com', config('services.inbound_mail.domain'));
        $this->assertSame('ops@example.com', config('mail.from.address'), 'unmanaged settings still apply');
    }
}
