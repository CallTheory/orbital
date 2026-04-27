<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\VoiceResource\Pages;
use App\Models\Voice;
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
 * Catalog of TTS voices that AI agent personas can use.
 *
 * One row per provider-specific voice. The `provider_voice_id` is what
 * actually gets sent to the upstream API at call time; the `name` is
 * just for humans browsing the list.
 */
class VoiceResource extends Resource
{
    protected static ?string $model = Voice::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-speaker-wave';

    protected static string|UnitEnum|null $navigationGroup = 'Conversational AI';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Voices';

    protected static ?string $modelLabel = 'Voice';

    protected static ?string $pluralModelLabel = 'Voices';

    protected static ?string $recordTitleAttribute = 'name';

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
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. "Ava — warm female receptionist"'),
                Forms\Components\Select::make('provider')
                    ->options(Voice::PROVIDER_OPTIONS)
                    ->required()
                    ->default('elevenlabs'),
                Forms\Components\TextInput::make('provider_voice_id')
                    ->label('Provider Voice ID')
                    ->required()
                    ->maxLength(255)
                    ->helperText('The actual identifier the provider expects (e.g. ElevenLabs voice_id, OpenAI voice name).'),
                Forms\Components\Select::make('gender')
                    ->options(Voice::GENDER_OPTIONS)
                    ->default('unspecified'),
                Forms\Components\TextInput::make('language')
                    ->maxLength(16)
                    ->placeholder('e.g. en-US')
                    ->helperText('BCP-47 language tag.'),
                Forms\Components\TextInput::make('accent')
                    ->maxLength(255)
                    ->placeholder('e.g. American, British, Spanish'),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('sample_url')
                    ->label('Sample audio URL')
                    ->url()
                    ->columnSpanFull()
                    ->helperText('Optional. Hosted preview audio file (mp3 or wav).'),
                Forms\Components\TagsInput::make('tags')
                    ->placeholder('warm, professional, energetic')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('provider')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Voice::PROVIDER_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'elevenlabs' => 'info',
                        'openai' => 'success',
                        'cartesia' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('provider_voice_id')
                    ->label('Voice ID')
                    ->fontFamily('mono')
                    ->limit(20)
                    ->copyable(),
                Tables\Columns\TextColumn::make('gender')
                    ->badge(),
                Tables\Columns\TextColumn::make('language')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->options(Voice::PROVIDER_OPTIONS),
                Tables\Filters\SelectFilter::make('gender')
                    ->options(Voice::GENDER_OPTIONS),
            ])
            ->actions([
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
            'index' => Pages\ListVoices::route('/'),
            'create' => Pages\CreateVoice::route('/create'),
            'edit' => Pages\EditVoice::route('/{record}/edit'),
        ];
    }
}
