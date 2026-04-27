<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrchestrationResource\Pages;
use App\Models\Orchestration;
use BackedEnum;
use Filament\Actions;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Platform-shared orchestrations only — the cross-client admin list
 * intentionally hides per-client orchestrations. Those live under
 * Client → Orchestrations and are out of scope for the platform
 * operator's "what shared workflows are available" view.
 *
 * "Active" is derived live from the queues that point at the
 * orchestration; the channel column showcases that linkage so authors
 * can see at a glance which platform orchestrations are wired up vs
 * sitting idle as drafts.
 */
class OrchestrationResource extends Resource
{
    protected static ?string $model = Orchestration::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'Workflow';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Orchestrations';

    protected static ?string $pluralModelLabel = 'Orchestrations';

    protected static ?string $modelLabel = 'Orchestration';

    protected static ?string $slug = 'orchestrations';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        // Per-client creation goes through Client → Orchestrations.
        // Platform-shared creation goes through ListOrchestrations'
        // "Create platform orchestration" header action.
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope('team')
            // Platform-only — per-client orchestrations live under
            // Client → Orchestrations and never appear here.
            ->whereNull('team_id')
            // Eager-load the queues + their owning client so the
            // channel-status icons + the modal's Assignments list
            // don't N+1 across (potentially many) clients.
            ->with([
                'callQueues:id,orchestration_id,team_id,name',
                'callQueues.team:id,name',
                'emailQueues:id,orchestration_id,team_id,name',
                'emailQueues.team:id,name',
            ]);
    }

    /**
     * The "details card" action shared by the Name and Description
     * column click handlers. Mirrors the per-client orchestration
     * pattern: name + description editable in the form, channels
     * render read-only below it, and Open editor / Duplicate /
     * Delete live in the modal footer.
     */
    public static function makeDetailsAction(): Actions\Action
    {
        return Actions\Action::make('details')
            ->modalHeading(fn (Orchestration $record) => $record->name)
            ->fillForm(fn (Orchestration $record): array => [
                'name' => $record->name,
                'description' => $record->description,
            ])
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(160),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->modalContent(fn (Orchestration $record) => view(
                'filament.modals.orchestration-details',
                ['record' => $record],
            ))
            ->modalSubmitActionLabel('Save')
            ->action(function (Orchestration $record, array $data) {
                $record->update($data);
                Notification::make()->title('Saved')->success()->send();
            })
            ->extraModalFooterActions([
                Actions\Action::make('open-editor')
                    ->label('Open editor')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->url(fn (Orchestration $record) => route('admin.flow-editor', ['orchestration' => $record->id]))
                    ->openUrlInNewTab(),
                Actions\Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(fn (Orchestration $record) => $record->duplicate())
                    ->requiresConfirmation()
                    ->modalHeading('Duplicate orchestration?')
                    ->modalDescription('Creates an unassigned copy with all flows / steps / transitions / rules.')
                    ->successNotificationTitle('Copy created'),
                Actions\DeleteAction::make()
                    ->modalDescription('Deletes the platform orchestration and every flow / step / transition / rule inside it. Any queue (across any client) pointing at this orchestration has its assignment cleared.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        $detailsAction = self::makeDetailsAction();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->action($detailsAction),
                Tables\Columns\TextColumn::make('description')
                    ->limit(70)
                    ->placeholder('—')
                    ->tooltip(fn (Orchestration $record) => $record->description)
                    ->action($detailsAction),
                Tables\Columns\TextColumn::make('channels')
                    ->label('Channels')
                    ->alignCenter()
                    ->state(fn (Orchestration $record): HtmlString => new HtmlString(
                        view('filament.columns.orchestration-channels', ['record' => $record])->render()
                    )),
            ])
            ->defaultSort('name')
            ->filters([])
            ->actions([])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('duplicate')
                        ->label('Duplicate')
                        ->icon('heroicon-o-document-duplicate')
                        ->requiresConfirmation()
                        ->modalHeading('Duplicate selected orchestrations?')
                        ->modalDescription('Each selected orchestration gets a copy with all flows / steps / transitions / rules cloned. Copies start unassigned (no queue points at them yet).')
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                /** @var Orchestration $record */
                                $record->duplicate();
                            }
                            Notification::make()
                                ->title($records->count() === 1 ? 'Copy created' : $records->count().' copies created')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrchestrations::route('/'),
        ];
    }
}
