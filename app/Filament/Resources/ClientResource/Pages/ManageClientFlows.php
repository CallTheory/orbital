<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\ClientChannelAssignment;
use App\Models\FlowGraph;
use App\Services\Flows\ChannelTriggerSeeder;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Per-client flow graphs + channel assignments.
 *
 * Two things on one page:
 *   1. **Channel assignments** — five dropdowns (one per channel
 *      type) where the author picks which active graph runs for
 *      this client's inbound phone / email / sms / wctp / outbound.
 *   2. **Graphs list** — the client's FlowGraph rows with status,
 *      flow count, and a launcher that opens the visual editor.
 *
 * Replaces the old per-flow IntakeFlows admin — flows are no longer
 * the unit of management; the graph is.
 */
class ManageClientFlows extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'flowGraphs';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Flow Graphs';

    protected static ?string $title = 'Flow Graphs';

    public static function getNavigationLabel(): string
    {
        return 'Flow Graphs';
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        // Bootstrap: ensure Default graph + 5 channel_assignment rows
        // exist on first visit. No-op once set up.
        app(ChannelTriggerSeeder::class)->ensureBootstrap($this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        // The "create a graph" form: just a name + optional
        // description. Status defaults to draft; authors promote
        // via the Promote action. The graph starts empty — the
        // editor backfills channel triggers on first visit.
        return $schema->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(160),
            Forms\Components\Textarea::make('description')
                ->rows(2),
            Forms\Components\Select::make('status')
                ->options([
                    FlowGraph::STATUS_DRAFT => 'Draft',
                    FlowGraph::STATUS_ACTIVE => 'Active',
                ])
                ->default(FlowGraph::STATUS_DRAFT)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
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
                Tables\Columns\TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('New graph')
                    ->mutateFormDataUsing(function (array $data): array {
                        return $data + ['team_id' => $this->getOwnerRecord()->id];
                    }),
                Actions\Action::make('channel-assignments')
                    ->label('Channel assignments')
                    ->icon('heroicon-o-link')
                    ->modalHeading('Which graph runs for each channel?')
                    ->modalDescription('Pick the active graph that handles each inbound / outbound channel. Only active graphs are assignable. Leave a channel blank to make it inert.')
                    ->form(function () {
                        $team = $this->getOwnerRecord();
                        $activeGraphs = FlowGraph::where('team_id', $team->id)
                            ->where('status', FlowGraph::STATUS_ACTIVE)
                            ->orderBy('name')
                            ->pluck('name', 'id');

                        return collect(ClientChannelAssignment::CHANNELS)
                            ->map(fn (string $c) => Forms\Components\Select::make($c)
                                ->label(ucfirst(str_replace('_', ' ', $c)))
                                ->options($activeGraphs)
                                ->placeholder('None — channel inert')
                                ->default(fn () => ClientChannelAssignment::where('team_id', $team->id)
                                    ->where('channel_type', $c)
                                    ->value('flow_graph_id')))
                            ->values()
                            ->all();
                    })
                    ->action(function (array $data) {
                        $team = $this->getOwnerRecord();
                        foreach (ClientChannelAssignment::CHANNELS as $channel) {
                            ClientChannelAssignment::updateOrCreate(
                                ['team_id' => $team->id, 'channel_type' => $channel],
                                ['flow_graph_id' => $data[$channel] ?? null],
                            );
                        }
                        Notification::make()
                            ->title('Channel assignments updated')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Actions\Action::make('open-editor')
                    ->label('Open editor')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (FlowGraph $record) => route('admin.flow-editor', ['graph' => $record->id]))
                    ->openUrlInNewTab(),
                Actions\Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(fn (FlowGraph $record) => $record->duplicate())
                    ->requiresConfirmation()
                    ->modalHeading('Duplicate graph?')
                    ->modalDescription('Creates a draft copy with all flows, steps, transitions, and rules cloned. Slots stay shared with the client. Open the copy in the editor to iterate on it.')
                    ->successNotificationTitle('Draft copy created'),
                Actions\Action::make('promote')
                    ->label('Promote to Active')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->visible(fn (FlowGraph $record) => $record->status === FlowGraph::STATUS_DRAFT)
                    ->action(fn (FlowGraph $record) => $record->update(['status' => FlowGraph::STATUS_ACTIVE]))
                    ->requiresConfirmation()
                    ->successNotificationTitle('Graph promoted to active'),
                Actions\Action::make('demote')
                    ->label('Demote to Draft')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->visible(fn (FlowGraph $record) => $record->status === FlowGraph::STATUS_ACTIVE)
                    ->action(function (FlowGraph $record) {
                        // Block demotion if any channel assignment still
                        // references this graph — the channel would go
                        // inert at runtime. Authors must pick a replacement
                        // first.
                        $blockers = $record->channelAssignments()->pluck('channel_type');
                        if ($blockers->isNotEmpty()) {
                            Notification::make()
                                ->title('Cannot demote — graph is still assigned')
                                ->body('In use on channel(s): '.$blockers->implode(', ').'. Reassign or clear those channels first.')
                                ->danger()
                                ->send();

                            return;
                        }
                        $record->update(['status' => FlowGraph::STATUS_DRAFT]);
                    })
                    ->requiresConfirmation(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->modalDescription('Deletes the graph and every flow / step / transition / rule inside it. Any channel assignment pointing at this graph will go inert (flow_graph_id → null) until reassigned.'),
            ])
            ->bulkActions([]);
    }
}
