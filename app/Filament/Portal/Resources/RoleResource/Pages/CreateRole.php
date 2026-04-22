<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\RoleResource\Pages;

use App\Filament\Portal\Resources\RoleResource;
use App\Services\Tenancy\TenantPermissionGatekeeper;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Stamp team_id on the role before insert so Spatie creates it
     * tenant-scoped. Permissions are split out and handled after
     * create — they run through the gatekeeper's allow-list check.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['team_id'] = auth()->user()?->current_team_id;
        $data['guard_name'] = 'web';

        // Remember the requested permissions and strip them off so
        // Filament doesn't try to fill them into the Role model
        // itself (Role has no `permissions` attribute).
        $this->cachedPermissions = array_values($data['permissions'] ?? []);
        unset($data['permissions']);

        return $data;
    }

    /** @var array<int, string> */
    protected array $cachedPermissions = [];

    /**
     * Sync permissions after the role row exists — the gatekeeper
     * needs a persisted Role to sync against. Flips the Spatie
     * team context temporarily since we may be creating the role
     * inside a different team than the registrar's default.
     */
    protected function afterCreate(): void
    {
        /** @var Model $record */
        $record = $this->record;
        $team = auth()->user()?->currentTeam;

        if (! $team) {
            return;
        }

        app(TenantPermissionGatekeeper::class)
            ->syncRolePermissions($team, $record, $this->cachedPermissions);

        Notification::make()
            ->success()
            ->title("Role '{$record->name}' created")
            ->send();
    }
}
