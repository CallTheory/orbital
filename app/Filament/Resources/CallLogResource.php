<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CallLogResource\Pages;
use App\Models\CallLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CallLogResource extends Resource
{
    protected static ?string $model = CallLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'Monitor';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('team');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('team.name')
                    ->label('Tenant')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('direction')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'inbound' => 'success',
                        'outbound' => 'info',
                        'internal' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('from_number')
                    ->label('From'),
                Tables\Columns\TextColumn::make('to_number')
                    ->label('To'),
                Tables\Columns\TextColumn::make('extension.number')
                    ->label('Ext'),
                Tables\Columns\TextColumn::make('agentPersona.name')
                    ->label('Agent')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => gmdate('H:i:s', $state)),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->options([
                        'inbound' => 'Inbound',
                        'outbound' => 'Outbound',
                        'internal' => 'Internal',
                    ]),
                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Tenant')
                    ->relationship('team', 'name', fn ($query) => $query->where('personal_team', false)),
            ])
            ->actions([
                \Filament\Actions\Action::make('play')
                    ->label('Play')
                    ->icon('heroicon-o-play-circle')
                    ->color('primary')
                    ->visible(fn (CallLog $record): bool => (bool) ($record->recording_path
                        || $record->recording_rx_path
                        || $record->recording_tx_path))
                    ->modalHeading(fn (CallLog $record) => 'Call recording — '.($record->from_number ?? '?').' → '.($record->to_number ?? '?'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('2xl')
                    ->modalContent(fn (CallLog $record) => view(
                        'filament.components.recording-player',
                        ['record' => $record],
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCallLogs::route('/'),
        ];
    }
}
