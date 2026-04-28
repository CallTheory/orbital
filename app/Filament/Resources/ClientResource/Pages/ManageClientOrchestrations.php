<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\Orchestration;
use App\Services\Flows\ChannelTriggerSeeder;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * Per-client orchestrations.
 *
 * Lists the client's orchestrations with their flow count and the
 * queues currently assigned to each. There's no manual status badge
 * — "Active" is derived from the assigned queues. Authors create a
 * new orchestration here, hit Open Editor to lay out the canvas,
 * and then assign it to a queue from the Call Queues / Email Queues
 * pages to make it routable.
 */
class ManageClientOrchestrations extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'orchestrations';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Orchestrations';

    protected static ?string $title = 'Orchestrations';

    public static function getNavigationLabel(): string
    {
        return 'Orchestrations';
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        // Bootstrap: ensure the client has a Default orchestration on
        // first visit. No-op once set up.
        app(ChannelTriggerSeeder::class)->ensureBootstrap($this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(160),
            Forms\Components\Textarea::make('description')->rows(2),
        ]);
    }

    /**
     * The "details card" action shared by the Name and Description
     * column click handlers. Same instance is bound to both columns
     * so clicking either opens the same modal — name + description
     * are editable in the form, channels render read-only below it,
     * and Open editor / Duplicate / Delete live in the modal footer.
     */
    protected function makeDetailsAction(): Actions\Action
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
                    ->modalDescription('Creates an unassigned copy with all flows / steps / transitions / rules. Slots stay shared with the client.')
                    ->successNotificationTitle('Copy created'),
                Actions\DeleteAction::make()
                    ->modalDescription('Deletes the orchestration and every flow / step / transition / rule inside it. Any queue pointing at this orchestration has its assignment cleared (queue stays valid; channel goes inert until reassigned).'),
            ]);
    }

    public function table(Table $table): Table
    {
        $detailsAction = $this->makeDetailsAction();

        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'flows' => fn ($q) => $q
                    ->select('id', 'orchestration_id', 'trigger_type', 'is_active')
                    ->withCount(['steps', 'transitionsOut']),
                'callQueues:id,orchestration_id,name',
                'emailQueues:id,orchestration_id,name',
            ]))
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
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('New orchestration')
                    ->mutateFormDataUsing(fn (array $data): array => $data + ['team_id' => $this->getOwnerRecord()->id]),
            ])
            ->actions([])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('duplicate')
                        ->label('Duplicate')
                        ->icon('heroicon-o-document-duplicate')
                        ->requiresConfirmation()
                        ->modalHeading('Duplicate selected orchestrations?')
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
}
