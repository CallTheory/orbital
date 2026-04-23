<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ExtensionResource\Pages;
use App\Models\Extension;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phone Extensions for the platform's PBX — hardware SIP phones, ATAs,
 * standalone SIP clients, etc. Things you can plug a device into.
 *
 * Staff softphones are auto-allocated as WebRTC extensions when a Staff
 * user is created and managed via the user's edit page — they don't show
 * up in this list.
 *
 * Client extensions (AI agents, virtual DIDs) live inside each client
 * under ClientResource → Extensions.
 */
class ExtensionResource extends Resource
{
    protected static ?string $model = Extension::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static string|UnitEnum|null $navigationGroup = 'Telephony';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Phone Extensions';

    protected static ?string $modelLabel = 'Phone Extension';

    protected static ?string $pluralModelLabel = 'Phone Extensions';

    protected static ?string $recordTitleAttribute = 'number';

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
     * Manual platform PBX extensions:
     *   - team_id IS NULL              → not tenant-scoped
     *   - type != 'staff_softphone'    → auto-managed staff softphones live on the Staff user page
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope('team')
            ->whereNull('team_id')
            ->whereIn('type', ['sip_phone', 'ata', 'softphone', 'webrtc_client']);
    }

    /**
     * Type → human label map. Used in the form select and the table column.
     *
     * @return array<string, string>
     */
    public static function deviceTypeOptions(): array
    {
        return [
            'sip_phone' => 'SIP Phone (hard phone)',
            'ata' => 'ATA (analog adapter)',
            'softphone' => 'Softphone (desktop SIP client)',
            'webrtc_client' => 'WebRTC Client (browser)',
        ];
    }

    /**
     * Default transport for each device type.
     */
    public static function defaultTransportFor(string $type): string
    {
        return match ($type) {
            'webrtc_client' => 'wss',
            default => 'udp',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('e.g. 100'),
                Forms\Components\TextInput::make('label')
                    ->maxLength(255)
                    ->placeholder('e.g. Front desk Polycom'),
                Forms\Components\Select::make('type')
                    ->options(self::deviceTypeOptions())
                    ->default('sip_phone')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Set $set) {
                        $set('transport', self::defaultTransportFor($state));
                    }),
                Forms\Components\Select::make('transport')
                    ->options([
                        'udp' => 'UDP',
                        'tcp' => 'TCP',
                        'tls' => 'TLS',
                        'wss' => 'WSS (WebSocket Secure)',
                    ])
                    ->default('udp')
                    ->required()
                    ->helperText(fn (callable $get) => $get('type') === 'webrtc_client'
                        ? 'WebRTC clients must use WSS.'
                        : null),
                Forms\Components\TextInput::make('sip_username')
                    ->label('SIP Username')
                    ->required(),
                Forms\Components\TextInput::make('sip_password')
                    ->label('SIP Password')
                    ->password()
                    ->revealable()
                    ->required(),
                Forms\Components\TextInput::make('context')
                    ->default('internal'),
                Forms\Components\Select::make('recording_mode')
                    ->label('Call recording')
                    ->options([
                        'inherit' => 'Inherit from client / platform',
                        'always' => 'Always record',
                        'never' => 'Never record',
                    ])
                    ->default('inherit')
                    ->native(false)
                    ->helperText('"Inherit" defers to the client override, which falls back to the platform default. Use "never" for sensitive lines (legal hotline, executive) regardless of client settings.'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::deviceTypeOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'sip_phone' => 'gray',
                        'ata' => 'gray',
                        'softphone' => 'info',
                        'webrtc_client' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('transport')
                    ->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('number')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(self::deviceTypeOptions()),
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
            'index' => Pages\ListExtensions::route('/'),
            'create' => Pages\CreateExtension::route('/create'),
            'edit' => Pages\EditExtension::route('/{record}/edit'),
        ];
    }
}
