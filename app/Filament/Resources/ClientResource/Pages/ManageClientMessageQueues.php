<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Support\OrchestrationBindingFormFactory;
use App\Models\AgentGroup;
use App\Models\AgentPersona;
use App\Models\MessageQueue;
use App\Models\Orchestration;
use App\Models\Team;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;

/**
 * Admin-side page for managing a client's message queues —
 * SMS, MMS, RCS, SMPP, WCTP, and paging traffic. One queue
 * can accept multiple protocols so a "Support" queue covers
 * every text inbound regardless of underlying transport.
 */
class ManageClientMessageQueues extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'messageQueues';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationLabel = 'Message Queues';

    protected static ?string $title = 'Message Queues';

    public static function getNavigationLabel(): string
    {
        return 'Message Queues';
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
            ->defaultSort('name')
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
     * @return array<int, Component>
     */
    public static function formSchemaFor(Team $owner): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->placeholder('Support, Alarms, Pager Relay, …'),

            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->maxLength(1000)
                ->placeholder('Optional note for operators about what kind of messages this queue holds.'),

            Forms\Components\Select::make('strategy')
                ->label('Claim strategy')
                ->options([
                    MessageQueue::STRATEGY_MANUAL => 'Manual — operators pull threads freely',
                    MessageQueue::STRATEGY_ROUND_ROBIN => 'Round robin — next thread → next operator in order',
                    MessageQueue::STRATEGY_LONGEST_IDLE => 'Longest idle — next thread → operator who hasn\'t worked recently',
                    MessageQueue::STRATEGY_AI_FIRST => 'AI first — overflow persona takes everything unless escalated',
                ])
                ->default(MessageQueue::STRATEGY_MANUAL)
                ->required(),

            Forms\Components\Select::make('agent_group_id')
                ->label('Operator group')
                ->options(fn () => AgentGroup::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->placeholder('Open — all operators')
                ->helperText('Only operators in this group will see threads in this queue. Leave empty for all operators.'),

            Forms\Components\Select::make('overflow_agent_persona_id')
                ->label('Overflow AI persona')
                ->options(fn () => AgentPersona::query()
                    ->where('team_id', $owner->id)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->placeholder('None — threads wait for a human')
                ->helperText('Optional. When nobody picks up, or when strategy=ai_first, the message hands off to this persona.'),

            Forms\Components\Toggle::make('is_active')->default(true),

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
                ->placeholder('None — queue holds threads but runs no AI flow')
                ->helperText('The orchestration this queue runs when an inbound message matches. Platform-shared orchestrations are tagged "Platform" — assign one, then click "Bindings" on the row to map its handles to your resources.'),

            Forms\Components\TagsInput::make('matched_addresses')
                ->label('Matched addresses')
                ->placeholder('e.g. +18005551212, 81234, support-pager')
                ->helperText('Phone numbers (E.164), shortcodes, or pager IDs that route to this queue. Matched against the inbound\'s "to" field.'),

            Forms\Components\Select::make('matched_protocols')
                ->label('Matched protocols')
                ->multiple()
                ->options([
                    MessageQueue::PROTOCOL_SMS => 'SMS',
                    MessageQueue::PROTOCOL_MMS => 'MMS',
                    MessageQueue::PROTOCOL_RCS => 'RCS',
                    MessageQueue::PROTOCOL_SMPP => 'SMPP',
                    MessageQueue::PROTOCOL_WCTP => 'WCTP',
                    MessageQueue::PROTOCOL_PAGING => 'Paging',
                ])
                ->placeholder('All protocols')
                ->helperText('Whitelist which transports route to this queue. Leave empty to accept any.'),
        ];
    }

    /**
     * @return array<int, Column>
     */
    public static function tableColumns(?Actions\Action $rowEditAction = null): array
    {
        $name = Tables\Columns\TextColumn::make('name')
            ->searchable()
            ->sortable()
            ->weight('medium');
        if ($rowEditAction) {
            $name->action($rowEditAction);
        }

        return [
            $name,
            Tables\Columns\TextColumn::make('description')
                ->limit(50)
                ->placeholder('—')
                ->toggleable(),
            Tables\Columns\TextColumn::make('strategy')
                ->badge(),
            Tables\Columns\TextColumn::make('orchestration.name')
                ->label('Orchestration')
                ->placeholder('— none —'),
            Tables\Columns\TextColumn::make('agentGroup.name')
                ->label('Operator group')
                ->placeholder('Open — all'),
            Tables\Columns\TextColumn::make('overflowAgent.name')
                ->label('Overflow AI')
                ->placeholder('None'),
            Tables\Columns\IconColumn::make('is_active')
                ->boolean(),
        ];
    }

    public static function editAction(Team $owner): Actions\Action
    {
        return Actions\Action::make('edit')
            ->modalHeading(fn (MessageQueue $record) => $record->name)
            ->fillForm(fn (MessageQueue $record): array => $record->only([
                'name', 'description', 'strategy', 'agent_group_id',
                'overflow_agent_persona_id', 'is_active', 'orchestration_id',
                'matched_addresses', 'matched_protocols',
            ]))
            ->schema(self::formSchemaFor($owner))
            ->modalSubmitActionLabel('Save')
            ->action(function (MessageQueue $record, array $data) {
                $record->update($data);
                Notification::make()->title('Saved')->success()->send();
            })
            ->extraModalFooterActions([
                self::bindingsAction($owner),
                Actions\DeleteAction::make()
                    ->modalDescription('Delete this queue? Threads pointing at it will lose their queue assignment.'),
            ]);
    }

    public static function bindingsAction(Team $owner): Actions\Action
    {
        return Actions\Action::make('bindings')
            ->label('Bindings')
            ->icon('heroicon-o-link')
            ->color('info')
            ->modalHeading(fn (MessageQueue $record) => "Bindings — {$record->name}")
            ->modalDescription('This queue uses a platform-shared orchestration. Map each binding handle to one of this client\'s resources so the orchestration runs against your data.')
            ->visible(fn (MessageQueue $record) => $record->orchestration?->isShared() ?? false)
            ->fillForm(fn (MessageQueue $record) => app(OrchestrationBindingFormFactory::class)
                ->loadValues($record->orchestration, $owner))
            ->schema(fn (MessageQueue $record): array => app(OrchestrationBindingFormFactory::class)
                ->fields($record->orchestration, $owner))
            ->action(function (MessageQueue $record, array $data) use ($owner) {
                app(OrchestrationBindingFormFactory::class)
                    ->save($record->orchestration, $owner, $data);
                Notification::make()
                    ->title('Bindings saved')
                    ->success()
                    ->send();
            });
    }
}
