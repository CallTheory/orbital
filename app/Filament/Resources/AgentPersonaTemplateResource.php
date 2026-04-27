<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AgentPersonaTemplateResource\Pages;
use App\Models\AgentPersona;
use App\Models\Team;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Platform-library template personas. `team_id = null` and `template_id = null`.
 *
 * When the platform operator edits a template, all linked client instances
 * automatically reflect the changes on unchanged fields (template propagation
 * via TemplateResolver). Clients can override any field on their instance to
 * break the link for that field.
 */
class AgentPersonaTemplateResource extends Resource
{
    protected static ?string $model = AgentPersona::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Conversational AI';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Personalities';

    protected static ?string $pluralModelLabel = 'Personalities';

    protected static ?string $modelLabel = 'Personality';

    protected static ?string $slug = 'personalities';

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

    /**
     * Only records where both team_id and template_id are null — the platform library.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope('team')
            ->whereNull('team_id')
            ->whereNull('template_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('Persona Template')
                    ->tabs([
                        Tab::make('Identity')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g. Warm Receptionist'),
                                Forms\Components\TextInput::make('role')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Front desk / Support / Intake'),
                                Forms\Components\Textarea::make('description')
                                    ->rows(3)
                                    ->helperText('Short description of when to use this template.'),
                                Forms\Components\Toggle::make('is_active')
                                    ->default(true),
                            ]),
                        Tab::make('Voice & LLM')
                            ->schema([
                                Forms\Components\Select::make('llm_provider')
                                    ->options([
                                        'anthropic' => 'Anthropic',
                                        'openai' => 'OpenAI',
                                        'openrouter' => 'OpenRouter',
                                        'local' => 'Local / Custom',
                                    ])
                                    ->default('anthropic')
                                    ->required(),
                                Forms\Components\TextInput::make('llm_model')
                                    ->default('claude-sonnet-4-20250514')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Select::make('stt_provider')
                                    ->options([
                                        'elevenlabs' => 'ElevenLabs',
                                        'deepgram' => 'Deepgram',
                                        'whisper' => 'Whisper',
                                    ])
                                    ->default('elevenlabs'),
                                Forms\Components\Select::make('tts_provider')
                                    ->options([
                                        'elevenlabs' => 'ElevenLabs',
                                        'openai' => 'OpenAI TTS',
                                        'cartesia' => 'Cartesia',
                                    ])
                                    ->default('elevenlabs'),
                                Forms\Components\TextInput::make('voice_id')
                                    ->label('Voice ID')
                                    ->placeholder('Provider-specific voice identifier'),
                            ]),
                        Tab::make('Prompts')
                            ->schema([
                                Forms\Components\Textarea::make('greeting')
                                    ->label('Inbound Greeting')
                                    ->rows(3),
                                Forms\Components\Textarea::make('outbound_greeting')
                                    ->label('Outbound Greeting')
                                    ->rows(3),
                                Forms\Components\Textarea::make('personality')
                                    ->rows(6),
                                Forms\Components\Textarea::make('system_prompt')
                                    ->label('System Prompt')
                                    ->rows(15),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->searchable(),
                Tables\Columns\TextColumn::make('llm_provider')
                    ->badge()
                    ->label('LLM'),
                Tables\Columns\TextColumn::make('instances_count')
                    ->counts('instances')
                    ->label('Used by'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->actions([
                Action::make('clone_to_tenant')
                    ->label('Clone to client')
                    ->icon('heroicon-o-document-duplicate')
                    ->form([
                        Forms\Components\Select::make('team_id')
                            ->label('Client')
                            ->options(fn () => Team::where('personal_team', false)->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (array $data, AgentPersona $record) {
                        $instance = AgentPersona::create([
                            'team_id' => (int) $data['team_id'],
                            'template_id' => $record->id,
                            'name' => $record->name,
                            'role' => $record->role,
                            // Other fields stay empty on the row — they resolve through the template
                            'is_active' => true,
                        ]);

                        Notification::make()
                            ->title('Cloned template to client')
                            ->body("Instance #{$instance->id} is live.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgentPersonaTemplates::route('/'),
            'create' => Pages\CreateAgentPersonaTemplate::route('/create'),
            'edit' => Pages\EditAgentPersonaTemplate::route('/{record}/edit'),
        ];
    }
}
