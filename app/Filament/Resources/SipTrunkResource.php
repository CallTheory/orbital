<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\PlatformManagedResource;
use App\Filament\Resources\SipTrunkResource\Pages;
use App\Models\SipTrunk;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class SipTrunkResource extends Resource
{
    use PlatformManagedResource;

    protected static ?string $model = SipTrunk::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|UnitEnum|null $navigationGroup = 'Telephony';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'SIP Trunks';

    protected static ?string $modelLabel = 'SIP Trunk';

    protected static ?string $pluralModelLabel = 'SIP Trunks';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('team_id')
                    ->label('Client')
                    ->relationship('team', 'name', fn ($query) => $query->where('personal_team', false))
                    ->searchable()
                    ->placeholder('Platform-wide (shared across all clients)')
                    ->helperText('Leave blank for a platform-shared trunk.'),
                Forms\Components\TextInput::make('provider')
                    ->placeholder('e.g. Twilio, Telnyx, VoIP.ms'),
                Forms\Components\TextInput::make('host')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('port')
                    ->numeric()
                    ->default(5060),
                Forms\Components\Select::make('transport')
                    ->options([
                        'udp' => 'UDP',
                        'tcp' => 'TCP',
                        'tls' => 'TLS',
                    ])
                    ->default('udp'),
                Forms\Components\TextInput::make('username'),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->revealable(),
                Forms\Components\Toggle::make('register')
                    ->helperText('Whether to send SIP REGISTER to this trunk'),
                Forms\Components\TextInput::make('inbound_context')
                    ->default('from-trunk'),
                Forms\Components\CheckboxList::make('codecs')
                    ->options([
                        'ulaw' => 'G.711 uLaw',
                        'alaw' => 'G.711 aLaw',
                        'g722' => 'G.722',
                        'opus' => 'Opus',
                        'gsm' => 'GSM',
                    ])
                    ->default(['ulaw', 'alaw', 'g722']),
                Forms\Components\TextInput::make('max_channels')
                    ->numeric()
                    ->placeholder('Unlimited'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
                Forms\Components\Textarea::make('notes')
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('team.name')
                    ->label('Client')
                    ->placeholder('Platform-wide')
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->searchable(),
                Tables\Columns\TextColumn::make('host'),
                Tables\Columns\TextColumn::make('transport')
                    ->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Client')
                    ->relationship('team', 'name', fn ($query) => $query->where('personal_team', false)),
            ])
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
            'index' => Pages\ListSipTrunks::route('/'),
            'create' => Pages\CreateSipTrunk::route('/create'),
            'edit' => Pages\EditSipTrunk::route('/{record}/edit'),
        ];
    }
}
