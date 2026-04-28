<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\SipTrunk;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ManageClientDids extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'dids';

    protected static ?string $modelLabel = 'DID';

    protected static ?string $pluralModelLabel = 'DIDs';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationLabel = 'DIDs';

    protected static ?string $title = 'Phone Numbers (DIDs)';

    public static function getNavigationLabel(): string
    {
        return 'DIDs';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->label('Phone Number')
                    ->placeholder('+15551234567')
                    ->required()
                    ->maxLength(32)
                    ->unique(ignoreRecord: true)
                    ->helperText('Use E.164 format (e.g. +15551234567).'),
                Forms\Components\TextInput::make('label')
                    ->maxLength(255)
                    ->placeholder('Primary, Backup, etc.'),
                Forms\Components\Select::make('sip_trunk_id')
                    ->label('Provisioned via Trunk')
                    ->options(fn () => SipTrunk::withoutGlobalScope('team')->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Which carrier trunk delivers calls to this DID.'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
                Forms\Components\Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        // Click the number cell to open the standard edit modal —
        // no inline action buttons cluttering each row. Bulk select
        // handles delete.
        $editAction = Actions\EditAction::make();

        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Number')
                    ->searchable()
                    ->weight('medium')
                    ->action($editAction),
                Tables\Columns\TextColumn::make('label')
                    ->placeholder('—')
                    ->action($editAction),
                Tables\Columns\TextColumn::make('sipTrunk.name')
                    ->label('Trunk')
                    ->placeholder('Any')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('number')
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
}
