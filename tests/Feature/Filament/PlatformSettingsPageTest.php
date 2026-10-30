<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\PlatformSettings;
use App\Services\Settings\PlatformSettingsRepository;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
}
