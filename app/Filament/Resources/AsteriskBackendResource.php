<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AsteriskBackendResource\Pages;
use App\Models\AsteriskBackend;
use App\Services\Telephony\AsteriskDispatcherWriter;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Platform-level CRUD for the Asterisk backend registry.
 *
 * Super-admin only. Every save/delete regenerates Kamailio's
 * dispatcher.list from the active rows and issues dispatcher.reload
 * on every Kamailio node in the VRRP pair (see AsteriskDispatcherWriter).
 * Adding a new node to the admin UI is a two-step operation:
 *   1. Launch the Asterisk container (docker compose up), wiring it
 *      to the shared ARA Postgres + the `ASTERISK_NODE_NAME` env.
 *   2. Add the hostname here. Kamailio starts probing it and
 *      dispatching to it on the next OPTIONS cycle.
 * Removing is the reverse: deactivate (or delete) here to pull from
 * the dispatcher pool, then stop the container.
 */
class AsteriskBackendResource extends Resource
{
    protected static ?string $model = AsteriskBackend::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Telephony';

    protected static ?string $navigationLabel = 'Asterisk Backends';

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'hostname';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('hostname')
                ->required()
                ->unique(ignoreRecord: true)
                ->regex('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/')
                ->helperText('Docker DNS hostname other services use to reach this Asterisk (e.g. asterisk-3).')
                ->placeholder('asterisk-3'),
            Forms\Components\TextInput::make('display_name')
                ->helperText('Operator-visible label. Falls back to hostname when blank.')
                ->placeholder('Asterisk 3'),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('sip_port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(5060)
                    ->required(),
                Forms\Components\TextInput::make('ami_port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(5038)
                    ->required(),
            ]),
            Forms\Components\TextInput::make('ami_host')
                ->helperText('Blank = use hostname. Only set if AMI listens on a different host.')
                ->placeholder('(same as hostname)'),
            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0)
                ->helperText('Lower numbers appear first in the SIP Proxy page cards.'),
            Forms\Components\Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive backends are excluded from the Kamailio dispatcher list entirely — no probes, no traffic.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('hostname')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('display_name')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('sip_port')
                    ->label('SIP')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ami_port')
                    ->label('AMI')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                EditAction::make()
                    ->after(fn () => static::regenerate()),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Removes this backend from the Kamailio dispatcher pool immediately. Existing in-flight calls on this node stay up; new calls route elsewhere.')
                    ->after(fn () => static::regenerate()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(fn () => static::regenerate()),
                ]),
            ]);
    }

    /**
     * Trigger dispatcher.list regeneration + Kamailio reload and
     * surface the outcome as a toast. Fires after every save /
     * delete / bulk-delete so the pool stays in sync with the DB
     * without the admin needing to click a separate "apply" button.
     */
    public static function regenerate(): void
    {
        $result = app(AsteriskDispatcherWriter::class)->regenerate();
        $allReloaded = ! empty($result['reloaded'])
            && ! in_array(false, $result['reloaded'], true);

        if ($result['wrote'] && $allReloaded) {
            Notification::make()
                ->title('Dispatcher regenerated')
                ->body('Kamailio is routing to the current backend list.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Dispatcher update incomplete')
            ->body($result['wrote']
                ? 'File written but one or more Kamailio nodes failed to reload. Re-apply from here or restart Kamailio.'
                : 'Failed to write dispatcher.list. Check Laravel logs.')
            ->danger()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsteriskBackends::route('/'),
            'create' => Pages\CreateAsteriskBackend::route('/create'),
            'edit' => Pages\EditAsteriskBackend::route('/{record}/edit'),
        ];
    }
}
