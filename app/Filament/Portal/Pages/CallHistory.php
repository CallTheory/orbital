<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Models\CallLog;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Client-scoped call history. Read-only — the portal is a view of
 * what the platform has captured on the customer's behalf.
 *
 * The table query explicitly filters on `current_team_id` rather than
 * relying on BelongsToTeam's global scope. Portal auth context doesn't
 * share state with the admin panel's team scope, and "filter explicitly"
 * beats "hope the global scope is configured right" for a page a client
 * user can view.
 */
class CallHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone-arrow-down-left';

    protected static string|UnitEnum|null $navigationGroup = 'Activity';

    protected static ?string $navigationLabel = 'Call History';

    protected static ?string $title = 'Call History';

    protected static ?int $navigationSort = -9;

    protected static ?string $slug = 'calls';

    protected string $view = 'filament.portal.pages.call-history';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CallLog::query()
                    ->where('team_id', (int) (auth()->user()->current_team_id ?? 0))
                    ->orderByDesc('started_at'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('started_at')
                    ->label('When')
                    ->dateTime('M j, Y g:i a')
                    ->timezone(fn () => auth()->user()?->displayTimezone() ?? config('app.timezone'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('direction')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'inbound' => 'info',
                        'outbound' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('from_number')
                    ->label('From')
                    ->searchable(),
                Tables\Columns\TextColumn::make('to_number')
                    ->label('To')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'missed', 'failed' => 'danger',
                        'in_progress' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? gmdate('i:s', (int) $state)
                        : '—'),
            ])
            ->defaultSort('started_at', 'desc')
            ->defaultPaginationPageOption(25)
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
}
