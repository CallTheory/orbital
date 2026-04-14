<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformRoleResource\Pages;

use App\Filament\Resources\PlatformRoleResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\PermissionRegistrar;

class CreatePlatformRole extends CreateRecord
{
    protected static string $resource = PlatformRoleResource::class;

    /** @var array<int, string>|null Permissions collected from the form between mutate and afterCreate. */
    protected ?array $pendingPermissions = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Team-less role: team_id is always null for platform roles.
        $data['team_id'] = null;
        $data['guard_name'] = 'web';

        // Collect the per-group `_perms_*` checkbox fields and stash them
        // for afterCreate to sync onto the new role.
        $this->pendingPermissions = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_perms_') && is_array($value)) {
                $this->pendingPermissions = array_merge($this->pendingPermissions, $value);
                unset($data[$key]);
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! empty($this->pendingPermissions)) {
            $registrar = app(PermissionRegistrar::class);
            $originalTeamId = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId(null);

            try {
                $this->record->syncPermissions(array_unique($this->pendingPermissions));
            } finally {
                $registrar->setPermissionsTeamId($originalTeamId);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
