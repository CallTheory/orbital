<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\IntakeGoalResource\Pages;
use App\Models\IntakeGoal;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Platform-library intake goal editor. Clients compose flows from this
 * library but cannot create goals themselves.
 *
 * The form is structured around the five JSON columns that every surface
 * (voice, operator, chat) reads: talking_points, data_fields, completion,
 * tools, knowledge_store_ids. The AgentFlowCompiler and future operator
 * compiler both walk these to produce their per-surface rendering.
 */
class IntakeGoalResource extends Resource
{
    protected static ?string $model = IntakeGoal::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|UnitEnum|null $navigationGroup = 'Workflow';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Intake Goals';

    protected static ?string $pluralModelLabel = 'Intake Goals';

    protected static ?string $modelLabel = 'Intake Goal';

    protected static ?string $slug = 'intake-goals';

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Identity')
                ->description('How this goal is identified in the library and referenced by flows.')
                ->icon('heroicon-o-identification')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('key')
                        ->required()
                        ->maxLength(64)
                        ->placeholder('identify_caller')
                        ->helperText('Stable slug referenced by flows and the seeder. Lowercase, underscores.'),
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Identify Caller'),
                    Forms\Components\Select::make('category')
                        ->options([
                            'intake' => 'Intake',
                            'routing' => 'Routing',
                            'escalation' => 'Escalation',
                            'knowledge' => 'Knowledge / FAQ',
                            'scheduling' => 'Scheduling',
                        ])
                        ->native(false),
                    Forms\Components\TextInput::make('icon')
                        ->maxLength(64)
                        ->placeholder('heroicon-o-user')
                        ->helperText('Heroicon name used by flow builders and operator UI.'),
                    Forms\Components\Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true)
                        ->columnSpanFull(),
                ]),

            Section::make('Talking Points')
                ->description('Ordered short prompts the agent or operator says to advance the goal.')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->collapsible()
                ->schema([
                    Forms\Components\Repeater::make('talking_points')
                        ->hiddenLabel()
                        ->simple(
                            Forms\Components\Textarea::make('text')
                                ->rows(2)
                                ->required()
                                ->placeholder('Ask the caller for their full name.'),
                        )
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['text'] ?? null)
                        ->addActionLabel('Add talking point'),
                ]),

            Section::make('Data Fields')
                ->description('Fields the goal collects. The AI compiler renders these as function-call schemas; the operator UI renders them as a form.')
                ->icon('heroicon-o-rectangle-stack')
                ->collapsible()
                ->schema([
                    Forms\Components\Repeater::make('data_fields')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('key')
                                ->required()
                                ->placeholder('caller_name')
                                ->maxLength(64),
                            Forms\Components\TextInput::make('label')
                                ->required()
                                ->placeholder('Caller name')
                                ->maxLength(128),
                            Forms\Components\Select::make('type')
                                ->required()
                                ->options([
                                    'string' => 'Text',
                                    'phone' => 'Phone number',
                                    'email' => 'Email',
                                    'number' => 'Number',
                                    'date' => 'Date',
                                    'datetime' => 'Date + time',
                                    'boolean' => 'Yes / No',
                                    'select' => 'Select',
                                    'textarea' => 'Long text',
                                ])
                                ->native(false),
                            Forms\Components\Toggle::make('required')
                                ->default(true),
                            Forms\Components\TextInput::make('hint')
                                ->columnSpanFull()
                                ->placeholder('First and last, as they prefer to be addressed.'),
                        ])
                        ->columns(2)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? null)
                        ->addActionLabel('Add data field'),
                ]),

            Section::make('Completion')
                ->description('How we know the goal is done.')
                ->icon('heroicon-o-check-circle')
                ->collapsible()
                ->schema([
                    Forms\Components\Select::make('completion.type')
                        ->label('Completion type')
                        ->options([
                            'all_required' => 'When all required data fields are collected',
                            'decision' => 'When a specific decision field is set',
                            'manual' => 'When the agent/operator manually advances',
                        ])
                        ->default('all_required')
                        ->native(false),
                    Forms\Components\TextInput::make('completion.decision_field')
                        ->label('Decision field key')
                        ->placeholder('transfer_confirmed')
                        ->helperText('Only used when completion type = decision.'),
                ]),

            Section::make('Tools')
                ->description('Tools the agent may invoke while the goal is active.')
                ->icon('heroicon-o-wrench-screwdriver')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\Components\Repeater::make('tools')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\Select::make('type')
                                ->options([
                                    'transfer_call' => 'Transfer call',
                                    'lookup_account' => 'Lookup account',
                                    'send_sms' => 'Send SMS',
                                    'create_ticket' => 'Create ticket',
                                ])
                                ->required()
                                ->native(false),
                            Forms\Components\KeyValue::make('config')
                                ->addActionLabel('Add config entry')
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->addActionLabel('Add tool'),
                ]),

            Section::make('Knowledge')
                ->description('Knowledge stores this goal may query via search_knowledge.')
                ->icon('heroicon-o-book-open')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('knowledge_store_ids')
                        ->hiddenLabel()
                        ->helperText('Comma-separated store IDs. A proper multi-select lands with Phase C once knowledge_stores is live.'),
                ]),

            Section::make('Surface Overrides')
                ->description('Optional per-surface tweaks when voice / operator / chat need different phrasing.')
                ->icon('heroicon-o-adjustments-horizontal')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Tabs::make('surface-overrides')
                        ->tabs([
                            Tab::make('Voice')->schema([
                                Forms\Components\Textarea::make('voice_overrides.greeting')
                                    ->label('Alternate greeting for voice calls')
                                    ->rows(2),
                            ]),
                            Tab::make('Operator')->schema([
                                Forms\Components\Textarea::make('operator_overrides.notes')
                                    ->label('Extra notes for the live operator')
                                    ->rows(3),
                            ]),
                            Tab::make('Chat')->schema([
                                Forms\Components\Textarea::make('chat_overrides.greeting')
                                    ->label('Alternate greeting for chat sessions')
                                    ->rows(2),
                            ]),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->size(TextSize::Small),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('category')
                    ->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('key')
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntakeGoals::route('/'),
            'create' => Pages\CreateIntakeGoal::route('/create'),
            'edit' => Pages\EditIntakeGoal::route('/{record}/edit'),
        ];
    }
}
