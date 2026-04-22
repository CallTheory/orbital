<?php

declare(strict_types=1);

namespace App\Filament\Resources\AllUsersResource\Pages;

use App\Filament\Resources\AllUsersResource;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class EditAllUser extends EditRecord
{
    protected static string $resource = AllUsersResource::class;

    /**
     * Redirect `/admin/users/{staff_id}/edit` to the Staff resource's
     * edit page. Without this, hitting the URL of a platform staff
     * user 404s because AllUsersResource::getEloquentQuery() scopes
     * out anyone with a team-less (platform-level) role. Lets the
     * operator paste any user ID into the URL and land on the right
     * editor without having to know which resource owns the record.
     */
    public function mount(int|string $record): void
    {
        $user = User::find($record);

        if ($user && AllUsersResource::isStaffUser($user)) {
            $this->redirect(
                \App\Filament\Resources\UsersResource::getUrl('edit', ['record' => $user]),
                navigate: true,
            );
            return;
        }

        parent::mount($record);
    }

    /**
     * Header is a single "Actions" dropdown grouping every out-of-band
     * user-management operation a super-admin needs. Kept in one menu
     * so the page header doesn't grow a new button every time we add
     * one (2FA disable, force verification, resend reset link, etc.).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('generatePassword')
                    ->label('Generate new password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Generate new password')
                    ->modalDescription('Generates a random password and saves it immediately. The raw value is shown once, in a persistent toast, so you can share it out-of-band. Orbital does not email it.')
                    ->action(function (): void {
                        /** @var User $record */
                        $record = $this->record;
                        $new = Str::random(16);
                        $record->forceFill(['password' => Hash::make($new)])->save();
                        Notification::make()
                            ->title('Password reset')
                            ->body("New password: {$new}")
                            ->persistent()
                            ->success()
                            ->send();
                    }),

                Actions\Action::make('sendResetLink')
                    ->label('Email password reset link')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalHeading('Email password reset link')
                    ->modalDescription('Sends the standard Fortify password-reset email so the user can pick their own new password. The current password keeps working until they complete the flow.')
                    ->action(function (): void {
                        /** @var User $record */
                        $record = $this->record;
                        $status = Password::broker()->sendResetLink(['email' => $record->email]);

                        if ($status === Password::RESET_LINK_SENT) {
                            Notification::make()
                                ->title('Reset link sent')
                                ->body("Reset email queued to {$record->email}.")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Could not send reset link')
                                ->body("Broker returned: {$status}")
                                ->danger()
                                ->send();
                        }
                    }),

                Actions\Action::make('disableTwoFactor')
                    ->label('Disable 2FA')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (): bool => (bool) $this->record->two_factor_confirmed_at)
                    ->requiresConfirmation()
                    ->modalHeading('Disable two-factor authentication')
                    ->modalDescription('Clears the user\'s 2FA secret, recovery codes, and confirmation timestamp. They\'ll be able to sign in with just a password — tell them to re-enroll promptly.')
                    ->action(function (): void {
                        /** @var User $record */
                        $record = $this->record;
                        $record->forceFill([
                            'two_factor_secret' => null,
                            'two_factor_recovery_codes' => null,
                            'two_factor_confirmed_at' => null,
                        ])->save();
                        Notification::make()
                            ->title('2FA disabled')
                            ->body("{$record->email} no longer has 2FA enabled.")
                            ->success()
                            ->send();
                    }),

                Actions\Action::make('addToTenant')
                    ->label('Add to tenant')
                    ->icon('heroicon-o-building-office-2')
                    ->modalHeading('Add this user to a tenant')
                    ->modalDescription('Attaches this user to the selected tenant and assigns the tenant-scoped roles that go with the chosen pivot role. Mirrors the "Add existing user" action on Customers → Tenants → Users, just reached from the user side.')
                    ->schema([
                        Forms\Components\Select::make('team_id')
                            ->label('Tenant')
                            ->required()
                            ->searchable()
                            ->options(function () {
                                /** @var User $record */
                                $record = $this->record;
                                $currentIds = \DB::table('team_user')
                                    ->where('user_id', $record->id)
                                    ->pluck('team_id');
                                return Team::query()
                                    ->where('personal_team', false)
                                    ->whereNotIn('id', $currentIds)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->helperText('Only tenants this user is not already attached to appear here.'),
                        Forms\Components\Select::make('role')
                            ->label('Tenant role')
                            ->options([
                                'admin' => 'Admin — can manage users and roles in this tenant',
                                'member' => 'Member — default invitee role (portal.view_* only)',
                            ])
                            ->default('admin')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $record */
                        $record = $this->record;
                        $tenant = Team::findOrFail($data['team_id']);
                        $this->attachUserToTenant($tenant, $record, $data['role']);
                    }),

                Actions\Action::make('forceReverifyEmail')
                    ->label('Force email re-verification')
                    ->icon('heroicon-o-envelope-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Force email re-verification')
                    ->modalDescription('Clears the email_verified_at timestamp and sends a fresh verification email. The user is signed out of verification-gated areas until they click the link.')
                    ->action(function (): void {
                        /** @var User $record */
                        $record = $this->record;
                        $record->forceFill(['email_verified_at' => null])->save();
                        $record->notify(new VerifyEmail);
                        Notification::make()
                            ->title('Verification email sent')
                            ->body("Sent to {$record->email}.")
                            ->success()
                            ->send();
                    }),

                Actions\DeleteAction::make(),
            ])
                ->label('Actions')
                ->icon('heroicon-o-ellipsis-vertical')
                ->button(),
        ];
    }

    /**
     * Attach $user to $tenant with the given pivot role and assign
     * the corresponding tenant-scoped Spatie roles. Mirrors the logic
     * in ManageTenantUsers::attachExistingUser — extracted here so the
     * action can be fired from the user side as well. Refreshes the
     * Livewire record after the save so the Tenant memberships panel
     * picks up the new row on the next render.
     */
    protected function attachUserToTenant(Team $tenant, User $user, string $role): void
    {
        $role = $role === 'admin' ? 'admin' : 'member';

        $tenant->users()->syncWithoutDetaching([
            $user->id => ['role' => $role],
        ]);

        $spatieRoles = $role === 'admin'
            ? [TenantProvisioner::ROLE_TENANT_ADMIN, TenantProvisioner::ROLE_TENANT_USER]
            : [TenantProvisioner::ROLE_TENANT_USER];

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->id);
        try {
            $user->assignRole($spatieRoles);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }

        // Flip current_team_id to the newly-attached tenant so a
        // follow-up `/portal` visit lands inside the tenant's context
        // (which is what you want when adding yourself to test the
        // customer portal). Previous guard only flipped when
        // current_team_id was null, which stranded super-admins
        // whose personal team was already their current team.
        $user->forceFill(['current_team_id' => $tenant->id])->save();

        $this->refreshFormData(['tenants']);

        Notification::make()
            ->success()
            ->title("{$user->email} added to {$tenant->name} as {$role}.")
            ->body('Their current tenant context has been switched to '.$tenant->name.'.')
            ->send();
    }
}
