<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SharedContactListResource\Pages;
use App\Models\SharedContactList;
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
 * Platform-level shared contact list. Created and managed by
 * super-admins, attached to one or more tenants via the
 * `team_shared_contact_list` pivot. Each attached tenant's
 * Contact queries automatically see rows from this list thanks
 * to the BelongsToTeamOrSharedPool scope on Contact — no
 * duplication of records across tenants.
 *
 * Phase-1 UI: manage the container (name / description) plus
 * the "Attach to tenants" action. Managing entries + field
 * definitions on shared lists lives in a follow-up pass — for
 * now super-admins seed them via tinker / the smart ingest
 * flow; the data model is complete but the dedicated editor UI
 * isn't yet.
 */
class SharedContactListResource extends Resource
{
    protected static ?string $model = SharedContactList::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'Features';

    protected static ?string $navigationLabel = 'Shared Contact Lists';

    protected static ?int $navigationSort = 20;

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
                    ->placeholder('Partner Escalation List'),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder('Describe what this shared list is for and which tenants should reference it.'),
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
                Tables\Columns\TextColumn::make('contacts_count')
                    ->label('Contacts')
                    ->counts('contacts')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('teams_count')
                    ->label('Attached tenants')
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
                    ->label('Attach tenants')
                    ->icon('heroicon-m-link')
                    ->color('primary')
                    ->modalHeading(fn (SharedContactList $record) => "Attach tenants to {$record->name}")
                    ->modalDescription('Select which tenants should see contacts from this shared list. Attached tenants\' Contact queries automatically include these rows via the shared-pool scope.')
                    ->schema([
                        Forms\Components\Select::make('team_ids')
                            ->label('Tenants')
                            ->multiple()
                            ->options(fn () => Team::query()
                                ->where('personal_team', false)
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->default(fn (SharedContactList $record) => $record->teams()->pluck('teams.id')->all())
                            ->searchable()
                            ->helperText('Unchecking a tenant detaches it. Re-attach anytime without losing data.'),
                    ])
                    ->action(function (SharedContactList $record, array $data) {
                        $teamIds = $data['team_ids'] ?? [];
                        // Sync the pivot, preserving is_active on
                        // rows that already existed (default new
                        // attachments to is_active=true).
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
                            ->title('Tenants updated')
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
            'index' => Pages\ListSharedContactLists::route('/'),
            'create' => Pages\CreateSharedContactList::route('/create'),
            'edit' => Pages\EditSharedContactList::route('/{record}/edit'),
        ];
    }
}
