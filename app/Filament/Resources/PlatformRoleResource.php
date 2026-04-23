<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformRoleResource\Pages;
use App\Filament\Support\RoleBadge;
use BackedEnum;
use Database\Seeders\PermissionCatalogSeeder;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Manages team-less Spatie roles — the platform-staff roles like
 * operator, supervisor, etc. Client-scoped roles (client_user) are
 * managed elsewhere.
 *
 * `super_admin` is built-in and locked: renaming or deleting it would
 * break the isSuperAdmin() checks scattered through the codebase. It
 * shows up in the list but can't be edited or deleted.
 *
 * Permissions assigned to roles come from the fixed catalog
 * (PermissionCatalogSeeder). New permissions can't be created at runtime —
 * only roles compose them.
 */
class PlatformRoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static ?string $slug = 'roles';

    protected static ?string $recordTitleAttribute = 'name';

    public const BUILTIN_ROLE = 'super_admin';

    /**
     * Permission groups shown on the role edit form. Order matters — sections
     * render top-to-bottom in this order. The `platform.*` group is omitted on
     * purpose: those permissions are reserved for super_admin.
     */
    public const PERMISSION_GROUPS = [
        'sip_trunk' => ['label' => 'SIP Trunks', 'icon' => 'heroicon-o-link'],
        'extension' => ['label' => 'Extensions', 'icon' => 'heroicon-o-phone'],
        'agent_persona' => ['label' => 'Agent Personas', 'icon' => 'heroicon-o-user-circle'],
        'script' => ['label' => 'Scripts', 'icon' => 'heroicon-o-document-text'],
        'call_queue' => ['label' => 'Call Queues', 'icon' => 'heroicon-o-queue-list'],
        'routing_rule' => ['label' => 'Routing Rules', 'icon' => 'heroicon-o-arrows-right-left'],
        'operating_hour' => ['label' => 'Operating Hours', 'icon' => 'heroicon-o-clock'],
        'call_log' => ['label' => 'Call Logs', 'icon' => 'heroicon-o-clipboard-document-list'],
        'softphone' => ['label' => 'Softphone', 'icon' => 'heroicon-o-device-phone-mobile'],
        'portal' => ['label' => 'Customer Portal', 'icon' => 'heroicon-o-globe-alt'],
        'tooling' => ['label' => 'System Tooling', 'icon' => 'heroicon-o-wrench-screwdriver'],
    ];

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
        // super_admin is editable but only for cosmetic fields (color).
        // The form disables name + permissions for it, and EditPlatformRole
        // re-syncs them defensively on save.
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin()
            && $record->name !== self::BUILTIN_ROLE;
    }

    /**
     * Team-less roles only.
     */
    public static function getEloquentQuery(): Builder
    {
        return Role::query()->whereNull('team_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Role')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Role $record) => $record?->name === self::BUILTIN_ROLE)
                            ->helperText(fn (?Role $record) => $record?->name === self::BUILTIN_ROLE
                                ? 'The super_admin role name is locked — it\'s referenced from code.'
                                : 'Use a short identifier (lowercase, no spaces) — e.g. "agent", "qa_lead".'),
                        Forms\Components\ColorPicker::make('color')
                            ->label('Display color')
                            ->default(RoleBadge::FALLBACK_COLOR)
                            ->helperText('Used for the role badge anywhere it appears in the admin UI.'),
                        Forms\Components\Hidden::make('guard_name')
                            ->default('web'),
                    ])
                    ->columns(2),

                ...self::permissionGroupSections(),
            ]);
    }

    /**
     * Build one collapsible Section per permission group, each containing
     * the CheckboxList for that group's permissions. Field names are
     * prefixed with `_perms_<group>` so they don't collide with model
     * columns; EditPlatformRole/CreatePlatformRole collect them on save
     * and call syncPermissions() with the merged list.
     *
     * @return array<int, Section>
     */
    protected static function permissionGroupSections(): array
    {
        $sections = [];

        foreach (self::PERMISSION_GROUPS as $prefix => $meta) {
            $options = self::permissionOptionsForGroup($prefix);
            if (empty($options)) {
                continue;
            }

            $sections[] = Section::make($meta['label'])
                ->icon($meta['icon'])
                ->collapsible()
                ->schema([
                    CheckboxList::make("_perms_{$prefix}")
                        ->hiddenLabel()
                        ->options($options)
                        ->columns(2)
                        ->bulkToggleable()
                        ->disabled(fn (?Role $record) => $record?->name === self::BUILTIN_ROLE)
                        ->afterStateHydrated(function (CheckboxList $component, ?Role $record) use ($prefix) {
                            if (! $record) {
                                return;
                            }
                            $component->state(
                                $record->permissions
                                    ->where('guard_name', 'web')
                                    ->pluck('name')
                                    ->filter(fn (string $name) => str_starts_with($name, "{$prefix}."))
                                    ->values()
                                    ->all(),
                            );
                        }),
                ]);
        }

        return $sections;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Role')
                    ->searchable()
                    ->sortable()
                    ->html()
                    ->formatStateUsing(fn (Role $record): string => RoleBadge::render(
                        $record->name,
                        $record->color ?: RoleBadge::FALLBACK_COLOR,
                    )->toHtml()),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('Permissions')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
                    ->alignCenter(),
            ])
            ->defaultSort('name')
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make()
                    ->visible(fn (Role $record) => $record->name !== self::BUILTIN_ROLE),
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
            'index' => Pages\ListPlatformRoles::route('/'),
            'create' => Pages\CreatePlatformRole::route('/create'),
            'edit' => Pages\EditPlatformRole::route('/{record}/edit'),
        ];
    }

    /**
     * Return the catalog options for a single group. Labels use just the
     * action portion (e.g. `view_any`) since the section header already
     * communicates the resource.
     *
     * @return array<string, string>
     */
    protected static function permissionOptionsForGroup(string $prefix): array
    {
        $options = [];
        foreach (PermissionCatalogSeeder::CATALOG as $name) {
            if (! str_starts_with($name, "{$prefix}.")) {
                continue;
            }
            $action = substr($name, strlen($prefix) + 1);
            $options[$name] = $action;
        }
        return $options;
    }
}
