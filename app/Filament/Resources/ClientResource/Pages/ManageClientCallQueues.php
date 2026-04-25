<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\AgentGroup;
use App\Models\AgentPersona;
use App\Models\QueueStrategyTemplate;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
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
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('agent_group_id')
                    ->label('Agent group')
                    ->options(fn () => AgentGroup::active()->orderBy('label')->pluck('label', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('The platform-level pool of humans + devices that ring when this queue activates. Manage in Platform → Agent Groups.'),
                Forms\Components\Select::make('strategy_template_id')
                    ->label('Strategy')
                    ->options(fn () => QueueStrategyTemplate::orderByDesc('is_default')->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => QueueStrategyTemplate::where('is_default', true)->value('id'))
                    ->required()
                    ->helperText('Platform-curated strategy template (strategy + timeout + retry + wrapup). Manage in Platform → Queue Strategies.'),
                Forms\Components\TextInput::make('max_callers')
                    ->numeric()
                    ->default(0)
                    ->helperText('0 = unlimited'),
                Forms\Components\Select::make('overflow_agent_persona_id')
                    ->label('Overflow AI Agent')
                    ->options(fn () => AgentPersona::withoutGlobalScope('team')
                        ->whereNotNull('team_id')
                        ->pluck('name', 'id'))
                    ->placeholder('None — callers wait')
                    ->helperText('Optional. If no human in the agent group answers, hand off to this AI persona.'),
                Forms\Components\Toggle::make('join_empty'),
                Forms\Components\Toggle::make('leave_when_empty')->default(true),
                Forms\Components\Select::make('dids')
                    ->label('DIDs routed to this queue')
                    ->multiple()
                    ->relationship(
                        name: 'dids',
                        titleAttribute: 'number',
                        modifyQueryUsing: fn ($query) => $query->where('team_id', $this->getOwnerRecord()->id),
                    )
                    ->preload()
                    ->helperText('Inbound calls to these DIDs ring this queue. A DID can belong to only one queue at a time.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('agentGroup.label')
                    ->label('Agent group')
                    ->placeholder('— not configured —'),
                Tables\Columns\TextColumn::make('strategyTemplate.name')
                    ->label('Strategy')
                    ->badge()
                    ->placeholder('— legacy —'),
                Tables\Columns\TextColumn::make('overflowAgent.name')
                    ->label('Overflow AI')
                    ->placeholder('None'),
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
