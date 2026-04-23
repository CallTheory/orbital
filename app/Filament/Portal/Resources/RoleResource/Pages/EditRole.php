<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\RoleResource\Pages;

use App\Filament\Portal\Resources\RoleResource;
use App\Services\Clients\ClientPermissionGatekeeper;
use App\Services\Clients\ClientProvisioner;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->record;
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ! RoleResource::isProtectedRole($record))
                ->before(function (Actions\Action $action) use ($record) {
                    // Block deletion when users currently hold the role —
                    // they'd silently lose whatever access this role
                    // granted with no warning. Force the admin to move
                    // members off first.
                    $assignedCount = $record->users()->count();
                    if ($assignedCount > 0) {
                        Notification::make()
                            ->danger()
                            ->title('Role is in use')
                            ->body("{$assignedCount} user(s) currently hold this role. Remove the role from them via Users before deleting.")
                            ->persistent()
                            ->send();
                        $action->cancel();
                    }
                }),
        ];
    }

    /**
     * Prefill the checkbox state from the role's current permissions
     * so the form shows what's currently granted instead of empty.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Model $record */
        $record = $this->record;
        $data['permissions'] = $record->permissions->pluck('name')->all();
        return $data;
    }

    /**
     * Protect seeded role names from being renamed — the app
     * references `client_admin` and `client_user` by name so
     * renaming them would break authorization code.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (RoleResource::isProtectedRole($this->record)) {
            // Force-keep the original name no matter what was submitted.
            $data['name'] = $this->record->name;
        }

        // Stash for the afterSave hook — see CreateRole for why.
        $this->cachedPermissions = array_values($data['permissions'] ?? []);
        unset($data['permissions']);

        return $data;
    }

    /** @var array<int, string> */
    protected array $cachedPermissions = [];

    protected function afterSave(): void
    {
        /** @var Model $record */
        $record = $this->record;
        $team = auth()->user()?->currentTeam;

        if (! $team) {
            return;
        }

        app(ClientPermissionGatekeeper::class)
            ->syncRolePermissions($team, $record, $this->cachedPermissions);
    }
}
