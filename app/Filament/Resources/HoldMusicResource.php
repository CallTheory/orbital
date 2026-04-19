<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\HoldMusicResource\Pages;
use App\Models\HoldMusicClass;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Manages Asterisk MOH (music-on-hold) classes.
 *
 * Each row maps to one MOH class in `musiconhold.conf`. The dial plan
 * generator reads this table at config-push time.
 *
 * The built-in `default` class is locked from rename/delete to keep
 * existing dial plan references stable.
 */
class HoldMusicResource extends Resource
{
    protected static ?string $model = HoldMusicClass::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-musical-note';

    protected static string|UnitEnum|null $navigationGroup = 'Telephony';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Hold Music';

    protected static ?string $modelLabel = 'Hold Music Class';

    protected static ?string $pluralModelLabel = 'Hold Music';

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
        return auth()->user()?->isSuperAdmin()
            && ! ($record instanceof HoldMusicClass && $record->isDefault());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Class name')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?HoldMusicClass $record) => $record?->isDefault())
                    ->dehydrated()
                    ->helperText('Asterisk MOH class identifier. Lowercase, no spaces. Becomes the literal name in musiconhold.conf.'),
                Forms\Components\TextInput::make('label')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Smooth Jazz, Office Pop'),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\Select::make('type')
                    ->options([
                        HoldMusicClass::TYPE_BUILTIN => 'Built-in (Asterisk bundled sounds)',
                        HoldMusicClass::TYPE_STREAM => 'External Stream (HTTP audio source)',
                        HoldMusicClass::TYPE_FILES => 'Audio Files (coming soon)',
                    ])
                    ->required()
                    ->live()
                    ->disabled(fn (?HoldMusicClass $record) => $record?->isDefault()),
                Forms\Components\TextInput::make('stream_url')
                    ->label('Stream URL')
                    ->placeholder('http://icecast:8000/jazz.mp3')
                    ->url()
                    ->helperText('Point at any HTTP audio stream. Use the bundled icecast container at http://icecast:8000/<mountpoint> or any external stream.')
                    ->required(fn (callable $get) => $get('type') === HoldMusicClass::TYPE_STREAM)
                    ->visible(fn (callable $get) => $get('type') === HoldMusicClass::TYPE_STREAM),
                Forms\Components\Select::make('stream_format')
                    ->options([
                        'mp3' => 'MP3',
                        'ogg' => 'Ogg Vorbis',
                        'aac' => 'AAC',
                        'wav' => 'WAV',
                    ])
                    ->default('mp3')
                    ->visible(fn (callable $get) => $get('type') === HoldMusicClass::TYPE_STREAM),
                Forms\Components\Placeholder::make('files_coming_soon')
                    ->label('')
                    ->content('File upload UI is coming in a future release. For now, use the Stream type pointed at the bundled Icecast container or an external stream.')
                    ->columnSpanFull()
                    ->visible(fn (callable $get) => $get('type') === HoldMusicClass::TYPE_FILES),
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
                    ->label('Class name')
                    ->fontFamily('mono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        HoldMusicClass::TYPE_BUILTIN => 'gray',
                        HoldMusicClass::TYPE_STREAM => 'info',
                        HoldMusicClass::TYPE_FILES => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('stream_url')
                    ->label('Source')
                    ->placeholder('—')
                    ->limit(40),
                Tables\Columns\IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('label')
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make()
                    ->visible(fn (HoldMusicClass $record) => ! $record->isDefault()),
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
            'index' => Pages\ListHoldMusic::route('/'),
            'create' => Pages\CreateHoldMusic::route('/create'),
            'edit' => Pages\EditHoldMusic::route('/{record}/edit'),
        ];
    }
}
