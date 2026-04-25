<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FlowGraphResource\Pages;
use App\Models\ClientChannelAssignment;
use App\Models\FlowGraph;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Cross-client list of flow graphs. Each row is one addressable
 * bundle (a whole canvas). Row actions:
 *   - Open in editor: launches /admin/flow-editor/{graph} as a popup.
 *
 * The Filament list replaces the confusing-one-row-per-flow
 * IntakeFlows admin; authors land here, pick a graph, open the
 * visual editor. No inline form — graph metadata edits happen in
 * the editor / client channel-assignment panel.
 */
class FlowGraphResource extends Resource
{
    protected static ?string $model = FlowGraph::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'Workflow';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Flow Graphs';

    protected static ?string $pluralModelLabel = 'Flow Graphs';

    protected static ?string $modelLabel = 'Flow Graph';

    protected static ?string $slug = 'flow-graphs';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false; // created via the Client panel (auto-bootstrapped) or Duplicate action
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Super-admin bypasses the team global scope so every client's
     * graphs are visible from this single cross-client view.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('team');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('team.name')
                    ->label('Client')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => FlowGraph::STATUS_DRAFT,
                        'success' => FlowGraph::STATUS_ACTIVE,
                    ]),
                Tables\Columns\TextColumn::make('flows_count')
                    ->label('Flows')
                    ->counts('flows')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('active_on_channels')
                    ->label('Active on channels')
                    ->getStateUsing(fn (FlowGraph $record): string => ClientChannelAssignment::query()
                        ->where('team_id', $record->team_id)
                        ->where('flow_graph_id', $record->id)
                        ->pluck('channel_type')
                        ->map(fn (string $c) => str_replace('_', ' ', $c))
                        ->join(', ') ?: '—'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('team.name')
            ->filters([
                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Client')
                    ->relationship('team', 'name', fn ($query) => $query->where('personal_team', false)),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        FlowGraph::STATUS_DRAFT => 'Draft',
                        FlowGraph::STATUS_ACTIVE => 'Active',
                    ]),
            ])
            ->actions([
                Action::make('open-editor')
                    ->label('Open in editor')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (FlowGraph $record) => route('admin.flow-editor', ['graph' => $record->id]))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlowGraphs::route('/'),
        ];
    }
}
