<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Team;
use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant contacts — customer-side users who log in to /portal to view
 * messages, calls, and recordings on behalf of the tenant.
 *
 * Relationship: Team -> users (Jetstream's pivot). This page shows users
 * attached to the current tenant, lets the platform operator add/remove
 * them, and assigns them the per-tenant `tenant_user` role.
 */
class ManageTenantContacts extends ManageRelatedRecords
{
    protected static string $resource = TenantResource::class;

    protected static string $relationship = 'contacts';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Contacts';

    protected static ?string $title = 'Tenant Contacts';

    public static function getNavigationLabel(): string
    {
        return 'Contacts';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(table: 'users', column: 'email', ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('Leave blank to generate a random password.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add Contact')
                    ->using(function (array $data): User {
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();

                        $user = User::create([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'password' => $data['password'] ?? Str::random(32),
                            'email_verified_at' => now(),
                            'current_team_id' => $team->id,
                        ]);

                        // Attach via Jetstream pivot
                        $team->users()->attach($user, ['role' => 'member']);

                        // Assign tenant_user role inside this team's context
                        $registrar = app(PermissionRegistrar::class);
                        $original = $registrar->getPermissionsTeamId();
                        $registrar->setPermissionsTeamId($team->id);
                        try {
                            $role = Role::where('team_id', $team->id)
                                ->where('name', 'tenant_user')
                                ->first();
                            if ($role) {
                                $user->assignRole($role);
                            }
                        } finally {
                            $registrar->setPermissionsTeamId($original);
                            $registrar->forgetCachedPermissions();
                        }

                        Notification::make()
                            ->title("Added {$user->name}")
                            ->body('Tenant contact created.')
                            ->success()
                            ->send();

                        return $user;
                    }),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DetachAction::make()
                    ->label('Remove')
                    ->modalHeading('Remove contact from tenant')
                    ->modalDescription('This removes the user from this tenant. The user account itself is preserved.'),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make()->label('Remove selected'),
                ]),
            ]);
    }
}
