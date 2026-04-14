<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UsersResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

/**
 * Platform staff only — super_admin, operator, supervisor.
 *
 * Tenant contacts (customer-side users with the per-tenant `tenant_user` role)
 * are managed inside their tenant via TenantResource → Contacts. They never
 * appear in this list.
 */
class UsersResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'Staff Member';

    protected static ?string $pluralModelLabel = 'Staff';

    protected static ?string $slug = 'staff';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Only users who hold any team-less platform role. Tenant contacts
     * (team-scoped tenant_user role) are excluded — they live under their
     * tenant in TenantResource → Contacts.
     *
     * Uses a raw whereExists against model_has_roles instead of going
     * through Spatie's `roles` relationship, because that relationship
     * is runtime team-scoped via PermissionRegistrar — and inside the
     * admin panel the team context is set to the super-admin's personal
     * team, which would erroneously filter out team-less role assignments.
     */
    public static function getEloquentQuery(): Builder
    {
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');

        return parent::getEloquentQuery()
            ->whereExists(function ($query) use ($modelHasRoles) {
                $query->select(\DB::raw(1))
                    ->from($modelHasRoles)
                    ->whereColumn($modelHasRoles.'.model_id', 'users.id')
                    ->where($modelHasRoles.'.model_type', \App\Models\User::class)
                    ->whereNull($modelHasRoles.'.team_id');
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Account')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->label('Role')
                            ->options(fn () => Role::query()
                                ->whereNull('team_id')
                                ->orderBy('name')
                                ->pluck('name', 'name')
                                ->all())
                            ->required()
                            ->searchable()
                            ->helperText('Manage available roles in Platform → Roles.')
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->helperText('Leave blank to generate a random password.')
                            ->dehydrated(fn ($state) => filled($state))
                            ->revealable(),
                    ])
                    ->columns(2),

                \Filament\Schemas\Components\Section::make('Softphone')
                    ->icon('heroicon-o-phone')
                    ->description('SIP credentials for this staff member\'s browser softphone. Auto-allocated on user creation.')
                    ->schema([
                        Forms\Components\TextInput::make('softphone_extension')
                            ->label('Extension Number')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?User $record) {
                                if ($record) {
                                    $ext = app(\App\Services\Telephony\PlatformExtensionAllocator::class)
                                        ->extensionFor($record);
                                    $component->state($ext?->number ?? '(not yet allocated)');
                                }
                            }),
                        Forms\Components\TextInput::make('softphone_username')
                            ->label('SIP Username')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?User $record) {
                                if ($record) {
                                    $ext = app(\App\Services\Telephony\PlatformExtensionAllocator::class)
                                        ->extensionFor($record);
                                    $component->state($ext?->sip_username ?? '—');
                                }
                            }),
                        Forms\Components\TextInput::make('softphone_password')
                            ->label('SIP Password')
                            ->password()
                            ->revealable()
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?User $record) {
                                if ($record) {
                                    $ext = app(\App\Services\Telephony\PlatformExtensionAllocator::class)
                                        ->extensionFor($record);
                                    $component->state($ext?->sip_password ?? '—');
                                }
                            }),
                    ])
                    ->columns(2)
                    ->visibleOn('edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('platform_role')
                    ->label('Role')
                    ->html()
                    ->getStateUsing(fn (User $record) => \App\Filament\Support\RoleBadge::forName(
                        self::platformRoleFor($record),
                    )->toHtml()),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Return the user's team-less platform role name, if any.
     */
    public static function platformRoleFor(User $user): ?string
    {
        $registrar = app(PermissionRegistrar::class);
        $original = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            $user->unsetRelation('roles');
            return $user->roles->pluck('name')->first();
        } finally {
            $registrar->setPermissionsTeamId($original);
        }
    }

    /**
     * Assign a team-less platform role. Replaces any existing platform role.
     */
    public static function assignPlatformRole(User $user, string $roleName): void
    {
        $registrar = app(PermissionRegistrar::class);
        $original = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            $user->unsetRelation('roles');
            foreach ($user->roles as $existing) {
                $user->removeRole($existing);
            }
            $role = Role::whereNull('team_id')->where('name', $roleName)->firstOrFail();
            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($original);
            $registrar->forgetCachedPermissions();
        }
    }
}
