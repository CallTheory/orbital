<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformRoleResource\Pages;

use App\Filament\Resources\PlatformRoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class EditPlatformRole extends EditRecord
{
    protected static string $resource = PlatformRoleResource::class;

    /** @var array<int, string>|null Holds the permission names collected from the form between mutate and afterSave. */
    protected ?array $pendingPermissions = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->name !== PlatformRoleResource::BUILTIN_ROLE),
        ];
    }

    /**
     * Strip name when editing super_admin (it's locked) and pull the
     * `_perms_*` fields out of the form payload — they're not Role columns.
     * The collected list is handed to syncPermissions() in afterSave().
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->name === PlatformRoleResource::BUILTIN_ROLE) {
            unset($data['name']);
        }

        $this->pendingPermissions = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_perms_') && is_array($value)) {
                $this->pendingPermissions = array_merge($this->pendingPermissions, $value);
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Sync permissions after the role itself is saved. super_admin gets a
     * defensive re-sync to every permission in the catalog regardless of
     * what the form did (the checkbox lists are disabled for it anyway).
     */
    protected function afterSave(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            if ($this->record->name === PlatformRoleResource::BUILTIN_ROLE) {
                $allPerms = Permission::where('guard_name', 'web')->get();
                $this->record->syncPermissions($allPerms);
            } else {
                $this->record->syncPermissions(array_unique($this->pendingPermissions ?? []));
            }
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
        }

        $registrar->forgetCachedPermissions();
    }
}
