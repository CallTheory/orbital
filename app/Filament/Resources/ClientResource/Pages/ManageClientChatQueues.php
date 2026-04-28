<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Support\OrchestrationBindingFormFactory;
use App\Models\AgentGroup;
use App\Models\AgentPersona;
use App\Models\ChatQueue;
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

/**
 * Admin-side page for managing a client's chat queues —
 * interactive, session-shaped traffic from an embeddable
 * web widget, Slack DM, or Microsoft Teams. Each queue
 * binds to one `integration_type` and carries the auth/config
 * its transport needs. Sessions and message persistence are
 * deferred.
 */
class ManageClientChatQueues extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'chatQueues';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationLabel = 'Chat Queues';

    protected static ?string $title = 'Chat Queues';

    public static function getNavigationLabel(): string
    {
        return 'Chat Queues';
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
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function formSchemaFor(Team $owner): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->placeholder('Website Chat, Internal Slack, …'),

            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->maxLength(1000)
                ->placeholder('Optional note for operators about what kind of sessions this queue holds.'),

            Forms\Components\Select::make('strategy')
                ->label('Claim strategy')
                ->options([
                    ChatQueue::STRATEGY_MANUAL => 'Manual — operators pull sessions freely',
                    ChatQueue::STRATEGY_ROUND_ROBIN => 'Round robin — next session → next operator in order',
                    ChatQueue::STRATEGY_LONGEST_IDLE => 'Longest idle — next session → operator who hasn\'t worked recently',
                    ChatQueue::STRATEGY_AI_FIRST => 'AI first — overflow persona takes everything unless escalated',
                ])
                ->default(ChatQueue::STRATEGY_MANUAL)
                ->required(),

            Forms\Components\Select::make('integration_type')
                ->label('Integration')
                ->options([
                    ChatQueue::INTEGRATION_WEB_WIDGET => 'Embeddable web widget',
                    ChatQueue::INTEGRATION_SLACK => 'Slack',
                    ChatQueue::INTEGRATION_TEAMS => 'Microsoft Teams',
                ])
                ->required()
                ->default(ChatQueue::INTEGRATION_WEB_WIDGET)
                ->helperText('How this queue receives chat sessions.'),

            Forms\Components\KeyValue::make('integration_config')
                ->label('Integration config')
                ->keyLabel('Setting')
                ->valueLabel('Value')
                ->helperText('Per-integration credentials and options. Web widget: site_key. Slack: app_token, signing_secret. Teams: tenant_id, client_id.'),

            Forms\Components\Select::make('agent_group_id')
                ->label('Operator group')
                ->options(fn () => AgentGroup::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->placeholder('Open — all operators')
                ->helperText('Only operators in this group will see sessions in this queue. Leave empty for all operators.'),

            Forms\Components\Select::make('overflow_agent_persona_id')
                ->label('Overflow AI persona')
                ->options(fn () => AgentPersona::query()
                    ->where('team_id', $owner->id)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->placeholder('None — sessions wait for a human')
                ->helperText('Optional. When nobody picks up, or when strategy=ai_first, the session hands off to this persona.'),

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
                ->placeholder('None — queue holds sessions but runs no AI flow')
                ->helperText('The orchestration this queue runs when a chat session starts. Platform-shared orchestrations are tagged "Platform" — assign one, then click "Bindings" on the row to map its handles to your resources.'),
        ];
    }

    /**
     * @return array<int, \Filament\Tables\Columns\Column>
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
            Tables\Columns\TextColumn::make('integration_type')
                ->label('Integration')
                ->badge(),
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
            ->modalHeading(fn (ChatQueue $record) => $record->name)
            ->fillForm(fn (ChatQueue $record): array => $record->only([
                'name', 'description', 'strategy', 'agent_group_id',
                'overflow_agent_persona_id', 'is_active', 'orchestration_id',
                'integration_type', 'integration_config',
            ]))
            ->schema(self::formSchemaFor($owner))
            ->modalSubmitActionLabel('Save')
            ->action(function (ChatQueue $record, array $data) {
                $record->update($data);
                Notification::make()->title('Saved')->success()->send();
            })
            ->extraModalFooterActions([
                self::bindingsAction($owner),
                Actions\DeleteAction::make()
                    ->modalDescription('Delete this queue? Sessions pointing at it will lose their queue assignment.'),
            ]);
    }

    public static function bindingsAction(Team $owner): Actions\Action
    {
        return Actions\Action::make('bindings')
            ->label('Bindings')
            ->icon('heroicon-o-link')
            ->color('info')
            ->modalHeading(fn (ChatQueue $record) => "Bindings — {$record->name}")
            ->modalDescription('This queue uses a platform-shared orchestration. Map each binding handle to one of this client\'s resources so the orchestration runs against your data.')
            ->visible(fn (ChatQueue $record) => $record->orchestration?->isShared() ?? false)
            ->fillForm(fn (ChatQueue $record) => app(OrchestrationBindingFormFactory::class)
                ->loadValues($record->orchestration, $owner))
            ->schema(fn (ChatQueue $record): array => app(OrchestrationBindingFormFactory::class)
                ->fields($record->orchestration, $owner))
            ->action(function (ChatQueue $record, array $data) use ($owner) {
                app(OrchestrationBindingFormFactory::class)
                    ->save($record->orchestration, $owner, $data);
                Notification::make()
                    ->title('Bindings saved')
                    ->success()
                    ->send();
            });
    }
}
