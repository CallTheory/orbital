<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AgentGroupResource\Pages;
use App\Filament\Resources\AgentGroupResource\RelationManagers\MembersRelationManager;
use App\Models\AgentGroup;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Platform-level agent groups: pools of humans + devices that can take
 * inbound calls. Client queues reference these groups via FK.
 */
class AgentGroupResource extends Resource
{
    protected static ?string $model = AgentGroup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Groups';

    protected static ?string $modelLabel = 'Group';

    protected static ?string $pluralModelLabel = 'Groups';

    protected static ?string $slug = 'groups';

    protected static ?string $recordTitleAttribute = 'label';

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
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Group name')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->helperText('Slug used internally and in Asterisk dial plan generation. Lowercase, no spaces.'),
                Forms\Components\TextInput::make('label')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. All Operators, Bilingual, Overnight Crew'),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Slug')
                    ->fontFamily('mono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('members_count')
                    ->counts('members')
                    ->label('Members')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('call_queues_count')
                    ->counts('callQueues')
                    ->label('Used by queues')
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('label')
            ->actions([
                EditAction::make()
                    ->label('Open'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgentGroups::route('/'),
            'create' => Pages\CreateAgentGroup::route('/create'),
            'edit' => Pages\EditAgentGroup::route('/{record}/edit'),
        ];
    }
}
