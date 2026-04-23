<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Mail\ClientInvitationMail;
use App\Models\Team;
use App\Models\ClientInvitation;
use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Per-client Users tab. Lists the client's login users via Jetstream's
 * `team_user` pivot. Adds happen via the invitation flow (creates a
 * ClientInvitation + emails the signed link); the invitation controller
 * handles acceptance and attaches the team_user row on completion.
 *
 * This is deliberately *not* a CRUD page for the User model. Editing
 * a user's name / email / password is a cross-client operation and
 * belongs on the platform-level Users resource (AllUsersResource).
 * Here we only manage membership in this one client.
 */
class ManageClientUsers extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    /**
     * Jetstream's team_user pivot, exposed as Team->users() returning
     * User records with pivot data attached. Works as a relation-page
     * source without any custom query work.
     */
    protected static string $relationship = 'users';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $title = 'Users';

    public static function getNavigationLabel(): string
    {
        return 'Users';
    }

    public function form(Schema $schema): Schema
    {
        // No create/edit form — actions replace the default form flow.
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->sortable()->copyable(),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->state(fn (User $record): bool => $record->email_verified_at !== null)
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    // Coerce the timestamp to a plain bool up front
                    // so IconColumn renders the false icon instead of
                    // blanking out on a null state (which it would
                    // otherwise do for users who never enrolled).
                    ->state(fn (User $record): bool => $record->two_factor_confirmed_at !== null)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (User $record): string => $record->two_factor_confirmed_at
                        ? 'Two-factor authentication is enabled.'
                        : 'Two-factor authentication has not been set up yet.')
                    ->sortable(),
            ])
            // Row click drills into the platform-level Users edit page
            // so the operator has full account controls (password reset,
            // 2FA, verification) without duplicating UI here. Client-
            // specific removal lives in the bulk actions below.
            ->recordUrl(fn (User $record): string => \App\Filament\Resources\AllUsersResource::getUrl('edit', ['record' => $record]))
            ->headerActions([
                Actions\Action::make('attach_existing')
                    ->label('Add existing user')
                    ->icon('heroicon-o-user-plus')
                    ->modalHeading('Add an existing user to this client')
                    ->modalDescription('Attaches a User record that already exists on the platform (platform staff, or a user attached to another client) to this client. Skips the email invitation flow — use when you want to test a client portal with your own account, or bring an internal staff member in as an account manager.')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('User')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => User::query()
                                ->whereNotIn('id', \DB::table('team_user')
                                    ->where('team_id', $this->getOwnerRecord()->id)
                                    ->pluck('user_id'))
                                ->where(function ($q) use ($search) {
                                    $q->where('name', 'ilike', "%{$search}%")
                                        ->orWhere('email', 'ilike', "%{$search}%");
                                })
                                ->limit(25)
                                ->get()
                                ->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->email})"])
                                ->all())
                            ->getOptionLabelUsing(function ($value) {
                                $u = User::find($value);
                                return $u ? "{$u->name} ({$u->email})" : (string) $value;
                            })
                            ->helperText('Only users not already attached to this client are listed.'),
                        Forms\Components\Select::make('role')
                            ->label('Client role')
                            ->options([
                                'admin' => 'Admin — can manage users and roles in this client',
                                'member' => 'Member — default invitee role (portal.view_* only)',
                            ])
                            ->default('admin')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->attachExistingUser(
                            $this->getOwnerRecord(),
                            User::findOrFail($data['user_id']),
                            $data['role'],
                        );
                    }),
                Actions\Action::make('invite')
                    ->label('Invite user')
                    ->icon('heroicon-o-envelope')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->placeholder('person@example.com'),
                    ])
                    ->action(function (array $data): void {
                        $this->sendInvitation($this->getOwnerRecord(), $data['email']);
                    }),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('detach')
                        ->label('Remove from client')
                        ->icon('heroicon-o-user-minus')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Remove selected users from this client')
                        ->modalDescription('Detaches the team_user pivot and revokes the tenant-scoped roles (client_admin / client_user / any custom). The user accounts themselves are not deleted — use the Delete action for that.')
                        ->action(function ($records): void {
                            $client = $this->getOwnerRecord();
                            $blocked = [];
                            foreach ($records as $user) {
                                $reason = $this->guardDetach($client, $user);
                                if ($reason !== null) {
                                    $blocked[] = $reason;
                                }
                            }
                            if ($blocked !== []) {
                                Notification::make()
                                    ->danger()
                                    ->title('Cannot remove some users')
                                    ->body(implode("\n", $blocked))
                                    ->persistent()
                                    ->send();
                                return;
                            }
                            foreach ($records as $user) {
                                $this->removeUser($client, $user);
                            }
                            Notification::make()
                                ->success()
                                ->title($records->count().' user(s) removed from '.$client->name.'.')
                                ->send();
                        }),
                    Actions\BulkAction::make('delete')
                        ->label('Delete user accounts')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected user accounts')
                        ->modalDescription('Permanently deletes these User records platform-wide. They\'ll lose access to every client and every panel they had. Team ownership must be reassigned first — owners can\'t be deleted.')
                        ->action(function ($records): void {
                            $client = $this->getOwnerRecord();
                            $blocked = [];
                            foreach ($records as $user) {
                                if ((int) $client->user_id === (int) $user->id) {
                                    $blocked[] = "{$user->email} is the client owner — reassign ownership before deleting.";
                                }
                            }
                            if ($blocked !== []) {
                                Notification::make()
                                    ->danger()
                                    ->title('Cannot delete some users')
                                    ->body(implode("\n", $blocked))
                                    ->persistent()
                                    ->send();
                                return;
                            }
                            $count = $records->count();
                            foreach ($records as $user) {
                                $user->delete();
                            }
                            Notification::make()
                                ->success()
                                ->title("{$count} user account(s) deleted.")
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * Safety checks before detaching a user from a client.
     *
     * Returns a user-facing error string if the detach should be
     * blocked, or null if it's safe to proceed. Reasons to block:
     *   - User is the client owner (Team.user_id). Detaching would
     *     leave the team's owner reference pointing at a non-member.
     *   - User has no platform role AND no other client membership.
     *     Detaching would orphan the account — they'd still exist in
     *     the users table but couldn't log in anywhere. If the
     *     operator really wants to sever access, Delete is the
     *     honest answer.
     */
    protected function guardDetach(Team $client, User $user): ?string
    {
        if ((int) $client->user_id === (int) $user->id) {
            return "{$user->email} is the client owner — reassign ownership before removing.";
        }

        $hasOtherTeam = \DB::table('team_user')
            ->where('user_id', $user->id)
            ->where('team_id', '!=', $client->id)
            ->exists();

        $hasPlatformRole = \DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->whereNull('team_id')
            ->exists();

        if (! $hasOtherTeam && ! $hasPlatformRole) {
            return "{$user->email} is only on this client and has no platform role — removing would orphan the account. Use Delete user accounts if you want to revoke access entirely.";
        }

        return null;
    }

    /**
     * Attach an existing User to the client as $role (admin | member),
     * assigning the appropriate tenant-scoped Spatie roles in one step.
     * Skips the invitation email — use when bringing a platform staff
     * user in, or when a super-admin wants their own account on a
     * client to test the portal experience.
     */
    protected function attachExistingUser(Team $client, User $user, string $role): void
    {
        $role = $role === 'admin' ? 'admin' : 'member';

        $client->users()->syncWithoutDetaching([
            $user->id => ['role' => $role],
        ]);

        $spatieRoles = $role === 'admin'
            ? [\App\Services\Clients\ClientProvisioner::ROLE_CLIENT_ADMIN, \App\Services\Clients\ClientProvisioner::ROLE_CLIENT_USER]
            : [\App\Services\Clients\ClientProvisioner::ROLE_CLIENT_USER];

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($client->id);
        try {
            $user->assignRole($spatieRoles);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }

        // Flip current_team_id to this client so a direct /portal
        // visit lands inside the client's context. Applies even
        // when the user had a different current_team_id already —
        // adding them here is an explicit "put them here" action,
        // not just a soft reference.
        $user->forceFill(['current_team_id' => $client->id])->save();

        Notification::make()
            ->success()
            ->title("{$user->email} added to {$client->name} as {$role}.")
            ->body('Their current client context has been switched to '.$client->name.'.')
            ->send();
    }

    /**
     * Create (or refresh) a pending invitation for $email on this
     * client and send the email. Existing pending invitations get a
     * fresh token and expiry — operators re-sending an invitation
     * shouldn't have to cancel the old one first.
     */
    protected function sendInvitation(Team $client, string $email): void
    {
        $email = strtolower(trim($email));

        // Short-circuit: user already attached to this client.
        $existing = User::where('email', $email)->first();
        if ($existing && $client->users()->whereKey($existing->id)->exists()) {
            Notification::make()
                ->warning()
                ->title("{$email} is already a member of this client.")
                ->send();
            return;
        }

        $invitation = ClientInvitation::query()->updateOrCreate(
            ['team_id' => $client->id, 'email' => $email],
            [
                'invited_by_user_id' => auth()->id(),
                'accepted_at' => null,
            ] + ClientInvitation::freshTokenAttributes(),
        );

        Mail::to($email)->send(new ClientInvitationMail($invitation));

        Notification::make()
            ->success()
            ->title("Invitation sent to {$email}.")
            ->send();
    }

    /**
     * Detach the user from the client and remove the tenant-scoped
     * `client_user` role. Role removal has to happen inside a team-id
     * swap because Spatie's role assertions are team-scoped via
     * PermissionRegistrar.
     */
    protected function removeUser(Team $client, User $user): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($client->id);

        try {
            $role = Role::where('team_id', $client->id)
                ->where('name', 'client_user')
                ->first();
            if ($role && $user->hasRole($role)) {
                $user->removeRole($role);
            }
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }

        $client->users()->detach($user->id);

        if ((int) $user->current_team_id === (int) $client->id) {
            $user->forceFill(['current_team_id' => null])->save();
        }

        Notification::make()
            ->success()
            ->title("{$user->email} removed from {$client->name}.")
            ->send();
    }
}
