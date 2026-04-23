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

class ManageClientExtensions extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'extensions';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationLabel = 'Extensions';

    protected static ?string $title = 'Extensions';

    public static function getNavigationLabel(): string
    {
        return 'Extensions';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('e.g. 1001'),
                Forms\Components\Select::make('type')
                    ->options([
                        'ai_agent' => 'AI Agent',
                        'virtual' => 'Virtual / DID landing slot',
                    ])
                    ->default('ai_agent')
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('label')
                    ->maxLength(255),
                Forms\Components\Select::make('assignable_id')
                    ->label('AI Agent')
                    ->options(fn () => AgentPersona::withoutGlobalScope('team')
                        ->whereNotNull('team_id')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn (callable $get) => $get('type') === 'ai_agent')
                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Set $set) {
                        $set('assignable_type', $state ? AgentPersona::class : null);
                    }),
                Forms\Components\Hidden::make('assignable_type'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('label')->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ai_agent' => 'AI Agent',
                        'virtual' => 'Virtual',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'ai_agent' => 'success',
                        'virtual' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('assignable.name')
                    ->label('Assigned To')
                    ->placeholder('Unassigned'),
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
