<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrchestrationResource\Pages;
use App\Models\AgentPersona;
use App\Models\Orchestration;
use App\Models\Team;
use App\Services\Flows\ApplyOrchestrationTemplate;
use BackedEnum;
use Filament\Actions;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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
            // Eager-load the channel-trigger flows (for the icon row)
            // and the queues + owning client (for the Assignments
            // table) so neither path N+1s across many clients.
            ->with([
                'flows' => fn ($q) => $q
                    ->select('id', 'orchestration_id', 'trigger_type', 'is_active')
                    ->withCount(['steps', 'transitionsOut']),
                'callQueues:id,orchestration_id,team_id,name',
                'callQueues.team:id,name',
                'emailQueues:id,orchestration_id,team_id,name',
                'emailQueues.team:id,name',
                'messageQueues:id,orchestration_id,team_id,name',
                'messageQueues.team:id,name',
                'chatQueues:id,orchestration_id,team_id,name',
                'chatQueues.team:id,name',
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
                self::makeApplyToClientAction(),
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

    /**
     * Apply this shared orchestration to a client: copy it with its
     * default intake fields, set the client's handoff number, and point
     * the client's AI persona at it (ApplyOrchestrationTemplate).
     */
    public static function makeApplyToClientAction(): Actions\Action
    {
        return Actions\Action::make('apply-to-client')
            ->label('Apply to client')
            ->icon('heroicon-o-arrow-down-on-square-stack')
            ->modalHeading(fn (Orchestration $record) => 'Apply "'.$record->name.'" to a client')
            ->modalDescription('Copies the flows and their default intake fields into the client. Edit the client\'s fields afterwards under Clients → Intake Fields.')
            ->schema([
                Forms\Components\Select::make('team_id')
                    ->label('Client')
                    ->options(fn () => Team::query()->where('personal_team', false)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live(),
                Forms\Components\Select::make('agent_persona_id')
                    ->label('AI persona that answers')
                    ->options(fn (Get $get) => $get('team_id')
                        ? AgentPersona::withoutGlobalScopes()->where('team_id', $get('team_id'))->orderBy('name')->pluck('name', 'id')
                        : [])
                    ->helperText('Calls to this persona\'s AI extension will run the copied flow.')
                    ->searchable(),
                Forms\Components\TextInput::make('handoff_number')
                    ->label('Live operator\'s phone number')
                    ->placeholder('+15551234567')
                    ->tel()
                    ->helperText('Where the AI transfers callers once the fields are collected.'),
            ])
            ->action(function (Orchestration $record, array $data) {
                $team = Team::findOrFail($data['team_id']);
                $persona = filled($data['agent_persona_id'] ?? null)
                    ? AgentPersona::withoutGlobalScopes()->where('team_id', $team->id)->findOrFail($data['agent_persona_id'])
                    : null;

                app(ApplyOrchestrationTemplate::class)->apply($record, $team, $persona, $data['handoff_number'] ?? null);

                Notification::make()
                    ->title('Applied to '.$team->name)
                    ->body($persona ? $persona->name.' now runs it.' : 'Assign it to an AI persona to use it.')
                    ->success()
                    ->send();
            });
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
            ->actions([self::makeApplyToClientAction()])
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
