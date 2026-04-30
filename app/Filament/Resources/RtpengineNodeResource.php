<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RtpengineNodeResource\Pages;
use App\Models\RtpengineNode;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Platform-level CRUD for the rtpengine node registry.
 *
 * Super-admin only. Each row is one rtpengine media-relay daemon
 * the platform can target via NG protocol (drain / activate /
 * statistics) and scrape via Prometheus. Adding a row registers
 * the node with Failover Central + the SystemHealth probe; the
 * node is then reachable from the SIP Proxy and Failover Central
 * pages for drain operations.
 *
 * No dispatcher-list regen hook here — Kamailio's rtpengine module
 * talks to the local NG socket on each VM, so the registry is
 * purely an admin/UI/health concern.
 */
class RtpengineNodeResource extends Resource
{
    protected static ?string $model = RtpengineNode::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|UnitEnum|null $navigationGroup = 'Telephony';

    protected static ?string $navigationLabel = 'rtpengine Nodes';

    protected static ?int $navigationSort = 91;

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
                ->helperText('Docker DNS hostname other services use to reach this rtpengine (e.g. rtpengine-1).')
                ->placeholder('rtpengine-1'),
            Forms\Components\TextInput::make('display_name')
                ->helperText('Operator-visible label. Falls back to hostname when blank.')
                ->placeholder('Edge RTP 1'),
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('ng_host')
                    ->helperText('Blank = use hostname.')
                    ->placeholder('(same as hostname)'),
                Forms\Components\TextInput::make('ng_port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(22222)
                    ->required()
                    ->helperText('NG protocol UDP port (bencode control).'),
            ]),
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('prom_host')
                    ->helperText('Blank = use hostname.')
                    ->placeholder('(same as hostname)'),
                Forms\Components\TextInput::make('prom_port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(9059)
                    ->required()
                    ->helperText('Prometheus `--listen-prom` endpoint.'),
            ]),
            Forms\Components\TextInput::make('recording_spool_path')
                ->required()
                ->default('/var/spool/rtpengine')
                ->helperText('Where the recording-daemon writes per-leg WAV pairs on this node.'),
            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0)
                ->helperText('Lower numbers appear first in Failover Central + SIP Proxy cards.'),
            Forms\Components\Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive nodes are skipped by health probes and drain fan-out. Use this to take a node out of rotation entirely.'),
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
                Tables\Columns\TextColumn::make('ng_port')
                    ->label('NG')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('prom_port')
                    ->label('Prom')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('recording_spool_path')
                    ->label('Spool')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Removes this rtpengine node from the registry. Health probes stop, Failover Central drops the row, and Kamailio drain fan-out skips it. The container itself is unaffected — stop it separately if you want it gone entirely.'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRtpengineNodes::route('/'),
            'create' => Pages\CreateRtpengineNode::route('/create'),
            'edit' => Pages\EditRtpengineNode::route('/{record}/edit'),
        ];
    }
}
