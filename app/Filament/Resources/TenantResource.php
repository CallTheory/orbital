<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Team;
use App\Services\Tenancy\TenantPermissionGatekeeper;
use BackedEnum;
use Database\Seeders\PermissionCatalogSeeder;
use Filament\Forms;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TenantResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Tenants';

    protected static ?string $modelLabel = 'Tenant';

    protected static ?string $pluralModelLabel = 'Tenants';

    protected static ?string $recordTitleAttribute = 'name';

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
     * Tenants are non-personal teams. Personal teams (created by Jetstream
     * for owners) are platform-internal and not exposed in the tenant list.
     */
    public static function getEloquentQuery(): Builder
    {
        return Team::query()->where('personal_team', false);
    }

    /**
     * Tenant detail form. The simpler the better — sub-pages handle the rest.
     * Quotas and permission ceiling stay here because they're tenant-level
     * settings, not lists of related records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Tabs::make('Tenant')
                    ->tabs([
                        \Filament\Schemas\Components\Tabs\Tab::make('Details')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('account_number')
                                    ->label('Account Number')
                                    ->numeric()
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Customer-facing identifier. Manually assigned.'),
                                Forms\Components\Select::make('user_id')
                                    ->label('Owner')
                                    ->relationship('owner', 'name')
                                    ->searchable()
                                    ->required(),
                                Forms\Components\DateTimePicker::make('suspended_at')
                                    ->label('Suspended At')
                                    ->helperText('If set, the tenant is suspended.'),
                                Forms\Components\Hidden::make('personal_team')
                                    ->default(false),
                            ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Quotas')
                            ->icon('heroicon-o-scale')
                            ->schema([
                                Forms\Components\Placeholder::make('quotas_help')
                                    ->content('Tenants are read-only customer accounts; the platform owns their SIP trunks and extensions. Only concurrent-call capacity is exposed as a quota.')
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('max_concurrent_calls')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('Unlimited')
                                    ->helperText('Maximum number of simultaneous calls this tenant can have in flight. Leave blank for unlimited.'),
                            ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Permission Ceiling')
                            ->icon('heroicon-o-key')
                            ->schema([
                                Forms\Components\Placeholder::make('ceiling_help')
                                    ->content('Permissions tenant-side users can have. Default deployments have nothing configurable here — tenants are read-only customer accounts.')
                                    ->columnSpanFull(),
                                Forms\Components\CheckboxList::make('allowed_permissions')
                                    ->label('Allowed Permissions')
                                    ->options(self::groupedPermissionOptions())
                                    ->columns(2)
                                    ->bulkToggleable()
                                    ->columnSpanFull()
                                    ->afterStateHydrated(function (Forms\Components\CheckboxList $component, ?Team $record) {
                                        if ($record) {
                                            $names = app(TenantPermissionGatekeeper::class)
                                                ->allowedPermissionsFor($record);
                                            $component->state($names);
                                        }
                                    })
                                    ->dehydrated(false),
                            ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Recording')
                            ->icon('heroicon-o-microphone')
                            ->schema([
                                Forms\Components\Placeholder::make('recording_help')
                                    ->content('Per-tenant overrides for call recording. Leave any field blank to inherit the platform default. Individual extensions can still override these via their own recording_mode field.')
                                    ->columnSpanFull(),
                                Forms\Components\Select::make('recording_overrides.enabled')
                                    ->label('Recording')
                                    ->options([
                                        '' => 'Inherit platform default',
                                        '1' => 'Enabled',
                                        '0' => 'Disabled',
                                    ])
                                    // Stored as a real bool inside the
                                    // recording_overrides JSON. Without
                                    // this cast, `false` hydrates to the
                                    // empty string option ("Inherit")
                                    // instead of "Disabled".
                                    ->formatStateUsing(fn ($state) => self::triStateBool($state))
                                    ->native(false),
                                Forms\Components\Select::make('recording_overrides.format')
                                    ->label('Format')
                                    ->options([
                                        '' => 'Inherit platform default',
                                        'wav' => 'WAV (lossless)',
                                        'mp3' => 'MP3 (compressed)',
                                    ])
                                    ->native(false),
                                Forms\Components\TextInput::make('recording_overrides.retention_days')
                                    ->label('Retention (days)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('Inherit platform default')
                                    ->helperText('0 = keep forever. Leave blank to inherit.'),
                                Forms\Components\Select::make('recording_overrides.beep_on_record')
                                    ->label('Beep when recording starts')
                                    ->options([
                                        '' => 'Inherit platform default',
                                        '1' => 'Yes',
                                        '0' => 'No',
                                    ])
                                    ->formatStateUsing(fn ($state) => self::triStateBool($state))
                                    ->native(false),
                                Forms\Components\TextInput::make('recording_overrides.beep_interval_seconds')
                                    ->label('Periodic beep interval (seconds)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('Inherit platform default')
                                    ->helperText('Seconds between repeated notification beeps during an active recording. Required by some jurisdictions. 0 = only beep once at the start. Leave blank to inherit.'),
                                Forms\Components\Textarea::make('recording_overrides.disclosure_message')
                                    ->label('Disclosure message (TTS)')
                                    ->rows(3)
                                    ->placeholder('Inherit platform default')
                                    ->helperText('Optional text spoken to the caller at the start of a recorded call — e.g. "This call may be monitored or recorded for quality assurance." Leave blank to inherit the platform default.')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_number')
                    ->label('Account #')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('primary_did_number')
                    ->label('Primary DID')
                    ->state(fn (Team $record) => $record->primaryDid()?->number)
                    ->placeholder('—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('tenantDids', fn ($q) => $q->where('number', 'like', "%{$search}%"));
                    }),
                Tables\Columns\TextColumn::make('tenant_dids_count')
                    ->counts('tenantDids')
                    ->label('DIDs')
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean()
                    ->getStateUsing(fn (Team $record) => $record->suspended_at === null),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('account_number')
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->label('Open'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Sub-navigation for a single tenant. Renders as a sidebar inside the
     * tenant context — DIDs, Extensions, Call Queues, etc. each get their
     * own page.
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            Pages\EditTenant::class,
            Pages\ManageTenantDids::class,
            Pages\ManageTenantExtensions::class,
            Pages\ManageTenantCallQueues::class,
            Pages\ManageTenantRoutingRules::class,
            Pages\ManageTenantPersonas::class,
            Pages\ManageTenantIntakeGoals::class,
            Pages\ManageTenantFlows::class,
            Pages\ManageTenantContacts::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
            'dids' => Pages\ManageTenantDids::route('/{record}/dids'),
            'extensions' => Pages\ManageTenantExtensions::route('/{record}/extensions'),
            'call-queues' => Pages\ManageTenantCallQueues::route('/{record}/call-queues'),
            'routing-rules' => Pages\ManageTenantRoutingRules::route('/{record}/routing-rules'),
            'personas' => Pages\ManageTenantPersonas::route('/{record}/personas'),
            'intake-goals' => Pages\ManageTenantIntakeGoals::route('/{record}/intake-goals'),
            'intake-flows' => Pages\ManageTenantFlows::route('/{record}/intake-flows'),
            'contacts' => Pages\ManageTenantContacts::route('/{record}/contacts'),
        ];
    }

    /**
     * Build a CheckboxList options array keyed by resource group, excluding
     * platform-only permissions.
     *
     * @return array<string, string>
     */
    protected static function groupedPermissionOptions(): array
    {
        $options = [];
        foreach (PermissionCatalogSeeder::CATALOG as $name) {
            if (in_array($name, TenantPermissionGatekeeper::PLATFORM_ONLY, true)) {
                continue;
            }
            $options[$name] = $name;
        }
        return $options;
    }

    /**
     * Hydrate a recording-overrides tri-state bool into the exact string
     * option key its Select expects.
     *
     * The overrides column is cast as an array, so booleans round-trip
     * as real `true`/`false`. PHP's implicit string cast sends `false`
     * to `""` — which matches the "Inherit platform default" option
     * instead of "No" — so we translate explicitly before Filament
     * hydrates the field.
     *
     *   true   → '1'   (shows the positive option)
     *   false  → '0'   (shows the negative option)
     *   null   → ''    (shows "Inherit platform default")
     */
    protected static function triStateBool(mixed $state): string
    {
        if ($state === null || $state === '') {
            return '';
        }
        return $state ? '1' : '0';
    }
}
