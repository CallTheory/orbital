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

class ManageClientRoutingRules extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'routingRules';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Routing Rules';

    protected static ?string $title = 'Routing Rules';

    public static function getNavigationLabel(): string
    {
        return 'Routing Rules';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('sip_trunk_id')
                    ->label('Trunk constraint')
                    ->options(fn () => SipTrunk::withoutGlobalScope('team')->pluck('name', 'id'))
                    ->placeholder('Any trunk'),
                Forms\Components\Select::make('match_type')
                    ->options([
                        'did' => 'DID (called number)',
                        'request_uri_user' => 'SIP Request-URI user',
                        'to_header_user' => 'SIP To header user',
                        'from_header_user' => 'SIP From header user (caller ID)',
                        'pattern' => 'Regex pattern',
                    ])
                    ->default('did')
                    ->required(),
                Forms\Components\TextInput::make('match_pattern')
                    ->label('Match value')
                    ->placeholder('e.g. _X. or +1555.+')
                    ->helperText('Asterisk-style pattern or exact match.'),
                Forms\Components\Select::make('destination_type')
                    ->options([
                        'queue' => 'Call Queue',
                        'extension' => 'Extension',
                        'agent' => 'AI Agent',
                        'voicemail' => 'Voicemail',
                        'ivr' => 'IVR',
                    ])
                    ->default('queue')
                    ->required(),
                Forms\Components\TextInput::make('destination_id')
                    ->numeric()
                    ->label('Destination ID'),
                Forms\Components\TextInput::make('priority')
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('match_type')->badge(),
                Tables\Columns\TextColumn::make('match_pattern')->label('Match'),
                Tables\Columns\TextColumn::make('sipTrunk.name')->label('Trunk')->placeholder('Any'),
                Tables\Columns\TextColumn::make('destination_type')->badge(),
                Tables\Columns\TextColumn::make('priority')->sortable()->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('priority')
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
