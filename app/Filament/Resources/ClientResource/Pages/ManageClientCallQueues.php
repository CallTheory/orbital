<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Support\OrchestrationBindingFormFactory;
use App\Models\AgentGroup;
use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\Orchestration;
use App\Models\Team;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ManageClientCallQueues extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'callQueues';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Call Queues';

    protected static ?string $title = 'Call Queues';

    public static function getNavigationLabel(): string
    {
        return 'Call Queues';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(self::formSchemaFor($this->getOwnerRecord()));
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $editAction = self::editAction($owner);

        return $table
            ->recordTitleAttribute('name')
            ->columns(self::tableColumns($editAction))
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->actions([])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function formSchemaFor(Team $owner): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('agent_group_id')
                ->label('Agent group')
                ->options(fn () => AgentGroup::active()->orderBy('label')->pluck('label', 'id'))
                ->searchable()
                ->required()
                ->helperText('The platform-level pool of humans + devices that ring when this queue activates, and the ring strategy that governs them. Manage in Platform → Agent Groups.'),
            Forms\Components\Select::make('orchestration_id')
                ->label('Orchestration')
                ->options(fn () => Orchestration::query()
                    ->withoutGlobalScope('team')
                    ->where(function ($q) use ($owner) {
                        $q->where('team_id', $owner->id)->orWhereNull('team_id');
                    })
                    ->orderBy('team_id')
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Orchestration $o) => [
                        $o->id => $o->name.($o->isShared() ? ' — Platform' : ''),
                    ]))
                ->placeholder('None — queue runs without flow logic')
                ->helperText('The orchestration this queue runs when one of its DIDs receives a call. Platform-shared orchestrations are tagged "Platform" — pick one of those, then click "Bindings" on the row to map its handles to your client\'s personas / queues.'),
            Forms\Components\TextInput::make('wrapup_time')
                ->label('Wrap-up time')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->suffix('seconds')
                ->helperText('Downtime an agent gets after ending a call from this queue before they\'re eligible for the next one. Tune higher for queues that need post-call CRM logging or note-taking; 0 means immediately ready.'),
            Forms\Components\Select::make('overflow_agent_persona_id')
                ->label('Overflow AI Agent')
                ->options(fn () => AgentPersona::withoutGlobalScope('team')
                    ->whereNotNull('team_id')
                    ->pluck('name', 'id'))
                ->placeholder('None — callers wait')
                ->helperText('Optional. If no human in the agent group answers, hand off to this AI persona.'),
            Forms\Components\Select::make('dids')
                ->label('DIDs routed to this queue')
                ->multiple()
                ->relationship(
                    name: 'dids',
                    titleAttribute: 'number',
                    modifyQueryUsing: fn ($query) => $query->where('team_id', $owner->id),
                )
                ->preload()
                ->helperText('Inbound calls to these DIDs ring this queue. A DID can belong to only one queue at a time.'),
        ];
    }

    /**
     * @return array<int, \Filament\Tables\Columns\Column>
     */
    public static function tableColumns(?Actions\Action $rowEditAction = null): array
    {
        $name = Tables\Columns\TextColumn::make('name')->searchable()->sortable()->weight('medium');
        if ($rowEditAction) {
            $name->action($rowEditAction);
        }

        return [
            $name,
            Tables\Columns\TextColumn::make('agentGroup.label')
                ->label('Agent group')
                ->placeholder('— not configured —'),
            Tables\Columns\TextColumn::make('agentGroup.strategyTemplate.name')
                ->label('Strategy')
                ->badge()
                ->placeholder('— none —'),
            Tables\Columns\TextColumn::make('orchestration.name')
                ->label('Orchestration')
                ->placeholder('— none —'),
            Tables\Columns\TextColumn::make('overflowAgent.name')
                ->label('Overflow AI')
                ->placeholder('None'),
        ];
    }

    /**
     * Single action that opens the edit modal when the name column is clicked.
     * Save is the modal submit; Delete and Bindings (when applicable) live as
     * footer actions inside the same modal.
     */
    public static function editAction(Team $owner): Actions\Action
    {
        return Actions\Action::make('edit')
            ->modalHeading(fn (CallQueue $record) => $record->name)
            ->fillForm(fn (CallQueue $record): array => array_merge(
                $record->only([
                    'name', 'agent_group_id', 'orchestration_id',
                    'wrapup_time', 'overflow_agent_persona_id',
                ]),
                ['dids' => $record->dids->pluck('id')->all()],
            ))
            ->schema(self::formSchemaFor($owner))
            ->modalSubmitActionLabel('Save')
            ->action(function (CallQueue $record, array $data) {
                $dids = $data['dids'] ?? null;
                unset($data['dids']);
                $record->update($data);
                if (is_array($dids)) {
                    $record->dids()->sync($dids);
                }
                Notification::make()->title('Saved')->success()->send();
            })
            ->extraModalFooterActions([
                self::bindingsAction($owner),
                Actions\DeleteAction::make()
                    ->modalDescription('Delete this queue? Any DIDs routed here become unassigned.'),
            ]);
    }

    public static function bindingsAction(Team $owner): Actions\Action
    {
        return Actions\Action::make('bindings')
            ->label('Bindings')
            ->icon('heroicon-o-link')
            ->color('info')
            ->modalHeading(fn (CallQueue $record) => "Bindings — {$record->name}")
            ->modalDescription('This queue uses a platform-shared orchestration. Map each binding handle to one of this client\'s resources so the orchestration runs against your data.')
            ->visible(fn (CallQueue $record) => $record->orchestration?->isShared() ?? false)
            ->fillForm(fn (CallQueue $record) => app(OrchestrationBindingFormFactory::class)
                ->loadValues($record->orchestration, $owner))
            ->schema(fn (CallQueue $record): array => app(OrchestrationBindingFormFactory::class)
                ->fields($record->orchestration, $owner))
            ->action(function (CallQueue $record, array $data) use ($owner) {
                app(OrchestrationBindingFormFactory::class)
                    ->save($record->orchestration, $owner, $data);
                Notification::make()
                    ->title('Bindings saved')
                    ->success()
                    ->send();
            });
    }
}
