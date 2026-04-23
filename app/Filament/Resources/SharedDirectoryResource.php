<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SharedDirectoryResource\Pages;
use App\Models\SharedDirectory;
use App\Models\Team;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Platform-level shared directory — a phone book curated by the
 * platform operator and attached to one or more clients. Attached
 * clients see entries from this directory during call-time lookups
 * via the BelongsToTeamOrSharedPool scope on DirectoryEntry.
 */
class SharedDirectoryResource extends Resource
{
    protected static ?string $model = SharedDirectory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Preferences';

    protected static ?string $navigationLabel = 'Shared Directories';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Regional Vet Network'),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder('Describe what this shared directory is for and which clients reference it during call handling.'),
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
                Tables\Columns\TextColumn::make('description')
                    ->limit(60)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('entries_count')
                    ->label('Entries')
                    ->counts('entries')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('teams_count')
                    ->label('Attached clients')
                    ->counts('teams')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->timezone(fn () => auth()->user()?->displayTimezone() ?? config('app.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->actions([
                Action::make('attach')
                    ->label('Attach clients')
                    ->icon('heroicon-m-link')
                    ->color('primary')
                    ->modalHeading(fn (SharedDirectory $record) => "Attach clients to {$record->name}")
                    ->modalDescription('Select which clients should see entries from this shared directory during call handling. Attached clients\' DirectoryEntry queries automatically include these rows.')
                    ->schema([
                        Forms\Components\Select::make('team_ids')
                            ->label('Clients')
                            ->multiple()
                            ->options(fn () => Team::query()
                                ->where('personal_team', false)
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->default(fn (SharedDirectory $record) => $record->teams()->pluck('teams.id')->all())
                            ->searchable(),
                    ])
                    ->action(function (SharedDirectory $record, array $data) {
                        $teamIds = $data['team_ids'] ?? [];
                        $existing = $record->teams()->pluck('teams.id')->all();
                        $toDetach = array_diff($existing, $teamIds);
                        $toAttach = array_diff($teamIds, $existing);

                        if (! empty($toDetach)) {
                            $record->teams()->detach($toDetach);
                        }
                        foreach ($toAttach as $teamId) {
                            $record->teams()->attach($teamId, ['is_active' => true]);
                        }

                        Notification::make()
                            ->title('Clients updated')
                            ->body(sprintf(
                                '%d attached, %d detached',
                                count($toAttach),
                                count($toDetach),
                            ))
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
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
            'index' => Pages\ListSharedDirectories::route('/'),
            'create' => Pages\CreateSharedDirectory::route('/create'),
            'edit' => Pages\EditSharedDirectory::route('/{record}/edit'),
        ];
    }
}
