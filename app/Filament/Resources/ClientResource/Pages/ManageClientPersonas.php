<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\AgentPersona;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ManageClientPersonas extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'agentPersonas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'AI Agents';

    protected static ?string $title = 'AI Agents';

    public static function getNavigationLabel(): string
    {
        return 'AI Agents';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('template_id')
                    ->label('Template')
                    ->options(fn () => AgentPersona::withoutGlobalScope('team')
                        ->whereNull('team_id')
                        ->whereNull('template_id')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Optional. When set, unchanged fields inherit from the template.'),
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('role')->required()->maxLength(255),
                Forms\Components\Textarea::make('description')->rows(2)->columnSpanFull(),
                Forms\Components\TextInput::make('voice_id')
                    ->label('Voice ID')
                    ->placeholder('Provider-specific voice identifier'),
                Forms\Components\Select::make('llm_provider')
                    ->options([
                        'anthropic' => 'Anthropic',
                        'openai' => 'OpenAI',
                        'openrouter' => 'OpenRouter',
                        'local' => 'Local / Custom',
                    ])
                    ->default('anthropic'),
                Forms\Components\TextInput::make('llm_model')->default('claude-sonnet-4-20250514'),
                Forms\Components\Textarea::make('greeting')->label('Inbound Greeting')->rows(3)->columnSpanFull(),
                Forms\Components\Textarea::make('outbound_greeting')->label('Outbound Greeting')->rows(3)->columnSpanFull(),
                Forms\Components\Textarea::make('personality')->rows(6)->columnSpanFull(),
                Forms\Components\Textarea::make('system_prompt')->label('System Prompt')->rows(15)->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('role'),
                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->placeholder('None (original)'),
                Tables\Columns\TextColumn::make('llm_provider')->badge(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
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
