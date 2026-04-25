<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QueueStrategyTemplateResource\Pages;
use App\Models\QueueStrategyTemplate;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Platform-operator-owned call-queue strategy templates.
 *
 * Clients pick one of these when they create a CallQueue; raw
 * strategy / timeout / retry / wrapup_time knobs are off the
 * per-client form. Managed under Platform → Queue Strategies.
 */
class QueueStrategyTemplateResource extends Resource
{
    protected static ?string $model = QueueStrategyTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 6;

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
        return $schema->schema([
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
                ->default(QueueStrategyTemplate::STRATEGY_RINGALL),
            Forms\Components\TextInput::make('timeout')
                ->numeric()->default(30)->suffix('seconds')
                ->helperText('How long to ring before moving on.'),
            Forms\Components\TextInput::make('retry')
                ->numeric()->default(5)->suffix('seconds')
                ->helperText('Pause between retries when no agent answers.'),
            Forms\Components\TextInput::make('wrapup_time')
                ->numeric()->default(0)->suffix('seconds')
                ->helperText('Downtime an agent gets after ending a call before the next one rings.'),
            Forms\Components\Toggle::make('is_default')
                ->helperText('New queues default to this template. Only one template can be the default — choosing this unsets the previous default.')
                ->live()
                ->afterStateUpdated(function (?bool $state, ?QueueStrategyTemplate $record) {
                    if ($state && $record) {
                        QueueStrategyTemplate::where('id', '!=', $record->id)->update(['is_default' => false]);
                    }
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('strategy')->badge(),
                Tables\Columns\TextColumn::make('timeout')->suffix('s')->alignCenter(),
                Tables\Columns\TextColumn::make('retry')->suffix('s')->alignCenter(),
                Tables\Columns\TextColumn::make('wrapup_time')->label('Wrap-up')->suffix('s')->alignCenter(),
                Tables\Columns\IconColumn::make('is_default')->boolean(),
                Tables\Columns\TextColumn::make('call_queues_count')
                    ->counts('callQueues')
                    ->label('Used by')
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
