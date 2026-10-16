<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClientResource\Pages\ManageClientApiKeys;
use App\Filament\Resources\ClientResource\Pages\ManageClientIntakeFields;
use App\Models\ClientSlot;
use App\Models\Team;
use App\Models\TeamApiKey;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

class ClientIntakeFieldsPageTest extends TestCase
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

    public function test_create_intake_field_and_enforce_unique_key(): void
    {
        $team = Team::factory()->create(['personal_team' => false]);

        Livewire::test(ManageClientIntakeFields::class, ['record' => $team->getKey()])
            ->callTableAction('create', data: ['name' => 'pickup_zip', 'type' => 'string', 'label' => 'ZIP']);

        $this->assertDatabaseHas('client_slots', ['team_id' => $team->id, 'name' => 'pickup_zip', 'label' => 'ZIP']);

        Livewire::test(ManageClientIntakeFields::class, ['record' => $team->getKey()])
            ->callTableAction('create', data: ['name' => 'pickup_zip', 'type' => 'string'])
            ->assertHasTableActionErrors(['name']);

        Livewire::test(ManageClientIntakeFields::class, ['record' => $team->getKey()])
            ->callTableAction('create', data: ['name' => 'Bad Key', 'type' => 'string'])
            ->assertHasTableActionErrors(['name']);

        $this->assertSame(1, ClientSlot::where('team_id', $team->id)->count());
    }

    public function test_create_and_revoke_api_key(): void
    {
        $team = Team::factory()->create(['personal_team' => false]);

        Livewire::test(ManageClientApiKeys::class, ['record' => $team->getKey()])
            ->callTableAction('create', data: ['name' => 'Demo']);

        $key = TeamApiKey::where('team_id', $team->id)->firstOrFail();

        Livewire::test(ManageClientApiKeys::class, ['record' => $team->getKey()])
            ->callTableAction('revoke', $key);

        $this->assertTrue($key->fresh()->isRevoked());
    }
}
