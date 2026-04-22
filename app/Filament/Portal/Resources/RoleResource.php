<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources;

use App\Filament\Portal\Resources\RoleResource\Pages;
use App\Services\Tenancy\TenantPermissionGatekeeper;
use App\Services\Tenancy\TenantProvisioner;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Portal-side Role management for tenant admins.
 *
 * Scoped to the current tenant (team_id = current_team_id). Admins
 * can create, rename, and delete custom roles for their staff, and
 * pick which permissions each role carries — limited to the
 * permissions the platform operator has included on this tenant's
 * allow-list (`tenant_permission_grants`).
 *
 * Two seeded roles are protected from deletion:
 *   - tenant_admin — always holds the full allow-list; deleting it
 *     would leave the tenant with nobody who can manage users.
 *   - tenant_user  — fallback role assigned to every invitee.
 *
 * Every permission write goes through TenantPermissionGatekeeper,
 * which is the single sanctioned write path for tenant-scoped
 * role/permission mutations.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static ?string $slug = 'roles';

    /**
     * Gated on the `portal.manage_roles` permission. Every user
     * carrying `tenant_admin` has it by default; a tenant admin can
     * also grant it through a custom role via this very page. Team
     * owners (Team.user_id) stay a hard-coded safety net so a tenant
     * can't accidentally paint itself into a corner where nobody can
     * reach the management surface.
     */
    public static function canAccess(): bool
    {
        return static::viewerCanManageRoles();
    }

    public static function canViewAny(): bool
    {
        return static::viewerCanManageRoles();
    }

    public static function canCreate(): bool
    {
        return static::viewerCanManageRoles();
    }

    public static function canEdit($record): bool
    {
        return static::viewerCanManageRoles();
    }

    public static function canDelete($record): bool
    {
        return static::viewerCanManageRoles()
            && ! static::isProtectedRole($record);
    }

    /**
     * Scope to the current tenant's roles. Returns empty for users
     * with no current team — defense in depth so a broken session
     * can't leak roles from an unrelated tenant.
     */
    public static function getEloquentQuery(): Builder
    {
        $teamId = auth()->user()?->current_team_id;
        return parent::getEloquentQuery()
            ->when($teamId, fn (Builder $q) => $q->where('team_id', $teamId), fn (Builder $q) => $q->whereRaw('1 = 0'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make('Role details')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(125)
                        ->disabled(fn ($record) => $record && static::isProtectedRole($record))
                        ->dehydrated(fn ($record, $state) => ! ($record && static::isProtectedRole($record)))
                        ->helperText(fn ($record) => $record && static::isProtectedRole($record)
                            ? 'Name is locked for seeded roles — the application references them by name.'
                            : 'Short identifier shown on the Users page when assigning roles.'),
                ]),

            \Filament\Schemas\Components\Section::make('Permissions')
                ->description('The set of capabilities this role grants. Options reflect what your platform operator has enabled for this tenant.')
                ->schema([
                    Forms\Components\CheckboxList::make('permissions')
                        ->label('Permissions')
                        ->hiddenLabel()
                        ->options(fn () => static::allowedPermissionOptions())
                        ->descriptions(fn () => static::allowedPermissionDescriptions())
                        ->columns(1)
                        ->dehydrated(true)
                        ->default(fn ($record) => $record?->permissions?->pluck('name')->all() ?? []),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions'),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('Assigned users')
                    ->counts('users'),
                Tables\Columns\IconColumn::make('protected')
                    ->label('Seeded')
                    ->state(fn (Role $record) => static::isProtectedRole($record))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (Role $record) => static::isProtectedRole($record)
                        ? 'Seeded role — cannot be deleted.'
                        : 'Custom role — can be deleted when no users hold it.'),
            ])
            ->recordUrl(fn (Role $record): string => static::getUrl('edit', ['record' => $record]))
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * Can the current user manage roles in this tenant? Team owner
     * always counts (safety net against a tenant locking itself out
     * by revoking portal.manage_roles from every custom role); every
     * other user needs the permission explicitly.
     */
    public static function viewerCanManageRoles(): bool
    {
        $user = auth()->user();
        if (! $user || ! $user->current_team_id) {
            return false;
        }

        $team = $user->currentTeam;
        if ($team && (int) $team->user_id === (int) $user->id) {
            return true;
        }

        return (bool) $user->can('portal.manage_roles');
    }

    public static function isProtectedRole($record): bool
    {
        return in_array($record->name ?? '', [
            TenantProvisioner::ROLE_TENANT_ADMIN,
            TenantProvisioner::ROLE_TENANT_USER,
        ], true);
    }

    /**
     * Permission name => human label map for the CheckboxList.
     * Sourced from the tenant's allow-list so only permissions the
     * platform operator has enabled for this tenant show up.
     *
     * @return array<string, string>
     */
    public static function allowedPermissionOptions(): array
    {
        $teamId = auth()->user()?->current_team_id;
        if (! $teamId) {
            return [];
        }

        return \DB::table('tenant_permission_grants as g')
            ->join('permissions as p', 'p.id', '=', 'g.permission_id')
            ->where('g.team_id', $teamId)
            ->orderBy('p.name')
            ->pluck('p.name', 'p.name')
            ->all();
    }

    /**
     * Descriptions shown below each checkbox. Looks up a friendly
     * one-line description keyed by permission name, falling back
     * to the raw name when we don't have copy for it yet.
     *
     * @return array<string, string>
     */
    public static function allowedPermissionDescriptions(): array
    {
        $copy = [
            'portal.view_home' => 'See the portal dashboard with the recent activity summary.',
            'portal.view_calls' => 'See the call history, durations, and routing outcomes.',
            'portal.view_messages' => 'Read messages taken on behalf of the tenant.',
            'portal.view_recordings' => 'Listen to recorded call audio.',
            'portal.manage_users' => 'Invite users, remove users, and change which roles each user carries.',
            'portal.manage_roles' => 'Create, edit, and delete the roles available inside this tenant.',
        ];

        return $copy;
    }
}
