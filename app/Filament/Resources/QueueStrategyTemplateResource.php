<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QueueStrategyTemplateResource\Pages;
use App\Models\QueueStrategyTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Platform-operator-owned call-queue strategy templates.
 *
 * Templates carry the *ring* knobs only (strategy + timeout + retry).
 * Wrap-up lives on the individual call queue so the same agent pool
 * can have different post-call recovery per work type. Templates get
 * picked by agent groups, not by clients directly — the strategy
 * applies wherever a group is used.
 */
class QueueStrategyTemplateResource extends Resource
{
    protected static ?string $model = QueueStrategyTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Workflow';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Queue Strategies';

    protected static ?string $modelLabel = 'Queue Strategy';

    protected static ?string $pluralModelLabel = 'Queue Strategies';

    protected static ?string $slug = 'queue-strategies';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make()
                    ->columnSpan(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(120)->unique(ignoreRecord: true),
                        Forms\Components\Textarea::make('description')->rows(2),
                        Forms\Components\Select::make('strategy')
                            ->options([
                                QueueStrategyTemplate::STRATEGY_RINGALL => 'Ring All',
                                QueueStrategyTemplate::STRATEGY_ROUNDROBIN => 'Round Robin',
                                QueueStrategyTemplate::STRATEGY_LEASTRECENT => 'Least Recent',
                                QueueStrategyTemplate::STRATEGY_RANDOM => 'Random',
                                QueueStrategyTemplate::STRATEGY_FEWESTCALLS => 'Fewest Calls',
                            ])
                            ->required()
                            ->live()
                            ->default(QueueStrategyTemplate::STRATEGY_RINGALL),
                        Actions::make([
                            Action::make('explain_strategies')
                                ->label('How do these strategies work?')
                                ->link()
                                ->icon('heroicon-o-question-mark-circle')
                                ->modalHeading('Ring strategies')
                                ->modalDescription('Tap a card to make it the selected strategy, then hit "Use this strategy" to apply it to the form. Cancel keeps your existing pick.')
                                ->fillForm(fn (Get $get): array => ['strategy' => $get('strategy')])
                                ->schema([
                                    Forms\Components\Radio::make('strategy')
                                        ->hiddenLabel()
                                        ->options([
                                            QueueStrategyTemplate::STRATEGY_RINGALL => 'Ring All',
                                            QueueStrategyTemplate::STRATEGY_ROUNDROBIN => 'Round Robin',
                                            QueueStrategyTemplate::STRATEGY_LEASTRECENT => 'Least Recent',
                                            QueueStrategyTemplate::STRATEGY_RANDOM => 'Random',
                                            QueueStrategyTemplate::STRATEGY_FEWESTCALLS => 'Fewest Calls',
                                        ])
                                        ->descriptions([
                                            QueueStrategyTemplate::STRATEGY_RINGALL => 'All available agents ring at the same time. First to answer takes the call. Best for small teams where any agent can take any call. Cons: every phone rings, which feels noisy if you have a lot of agents and a non-urgent call.',
                                            QueueStrategyTemplate::STRATEGY_ROUNDROBIN => 'Rotates through agents in a fixed order — one phone at a time per ring cycle. Asterisk remembers position across calls so workload spreads evenly. Good when you want the next call to feel "next in line" instead of mass-paging the room.',
                                            QueueStrategyTemplate::STRATEGY_LEASTRECENT => 'Rings whichever agent has been idle the longest. Keeps the freshest agents available and prevents one or two operators from soaking up everything. Good for burst-heavy queues where reaction speed matters.',
                                            QueueStrategyTemplate::STRATEGY_RANDOM => 'Picks an agent at random each ring cycle. Useful when you don\'t want a predictable pattern — quality-monitoring scenarios, or when agents shouldn\'t be able to predict which calls they\'ll get. Workload still averages out over enough calls.',
                                            QueueStrategyTemplate::STRATEGY_FEWESTCALLS => 'Counts completed calls per agent and prefers the agent with the lowest count. Ensures everyone takes roughly the same volume of calls, even when one agent happens to handle longer ones.',
                                        ])
                                        ->required(),
                                ])
                                ->action(function (array $data, Set $set): void {
                                    $set('strategy', $data['strategy']);
                                })
                                ->modalSubmitActionLabel('Use this strategy')
                                ->modalCancelActionLabel('Cancel'),
                        ])->columnSpanFull(),
                        Forms\Components\TextInput::make('timeout')
                            ->numeric()->default(30)->suffix('seconds')
                            ->helperText('How long to ring before moving on.'),
                        Forms\Components\TextInput::make('retry')
                            ->numeric()->default(5)->suffix('seconds')
                            ->helperText('Internal scheduling pause between ring cycles when no agent answers. Caller hears continuous hold music throughout — they never notice this gap. 5s is the conventional default.'),
                        Forms\Components\Toggle::make('is_default')
                            ->helperText('New queues default to this template. Only one template can be the default — choosing this unsets the previous default.')
                            ->live()
                            ->afterStateUpdated(function (?bool $state, ?QueueStrategyTemplate $record) {
                                if ($state && $record) {
                                    QueueStrategyTemplate::where('id', '!=', $record->id)->update(['is_default' => false]);
                                }
                            }),
                    ]),
                Section::make('Used by agent groups')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\Placeholder::make('agent_groups_list')
                            ->hiddenLabel()
                            ->content(fn (?QueueStrategyTemplate $record) => view(
                                'filament.partials.queue-strategy-groups',
                                ['record' => $record],
                            )),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                // Inline "Default" badge sits next to the name. Renders
                // as an empty cell on non-default rows so the column
                // takes minimal width.
                Tables\Columns\TextColumn::make('default_marker')
                    ->label('')
                    ->state(fn (QueueStrategyTemplate $record): ?string => $record->is_default ? 'Default' : null)
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('strategy')->badge(),
                Tables\Columns\TextColumn::make('timeout')->suffix('s')->alignCenter(),
                Tables\Columns\TextColumn::make('retry')->suffix('s')->alignCenter(),
                Tables\Columns\TextColumn::make('agent_groups_count')
                    ->counts('agentGroups')
                    ->label('Used by groups')
                    ->alignCenter(),
            ])
            ->defaultSort('is_default', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQueueStrategyTemplates::route('/'),
            'create' => Pages\CreateQueueStrategyTemplate::route('/create'),
            'edit' => Pages\EditQueueStrategyTemplate::route('/{record}/edit'),
        ];
    }
}
