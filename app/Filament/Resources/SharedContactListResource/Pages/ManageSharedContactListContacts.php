<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedContactListResource\Pages;

use App\Filament\Resources\SharedContactListResource;
use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\SharedContactList;
use App\Services\Contacts\ColumnMappingGuesser;
use App\Services\Contacts\FieldFormBuilder;
use App\Services\Contacts\TabularImportService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Entries editor for a shared contact list. The form + list are
 * schema-driven: whatever ContactFieldDefinition rows exist on
 * the parent shared list are rendered as form inputs and as
 * toggleable table columns. Shape mirrors ManageTenantContacts,
 * but the rows are platform-owned with `shared_contact_list_id`
 * set and `team_id` NULL so the shared-pool scope on Contact
 * exposes them to every tenant attached to this list.
 *
 * Deliberately skips the portal-access, smart-ingest, and CSV
 * import actions available on the tenant version — those all
 * assume a tenant context (which role → which User → which
 * tenant_user role). Shared lists can add those in a follow-up
 * if super-admins need bulk import at this layer; for now the
 * path is: define the schema, add entries one by one (or via
 * tinker), attach the list to tenants.
 */
class ManageSharedContactListContacts extends ManageRelatedRecords
{
    protected static string $resource = SharedContactListResource::class;

    protected static string $relationship = 'contacts';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Entries';

    protected static ?string $title = 'Shared List Entries';

    public static function getNavigationLabel(): string
    {
        return 'Entries';
    }

    public function form(Schema $schema): Schema
    {
        $definitions = $this->loadDefinitions();

        if ($definitions->isEmpty()) {
            // No schema yet — point the user at the Fields sub-nav
            // rather than rendering an empty form with nowhere to
            // type. The create action stays disabled in the table
            // via the same check.
            return $schema->schema([
                \Filament\Forms\Components\Placeholder::make('no_fields')
                    ->label('')
                    ->content('Define at least one field on the Fields tab before adding entries.'),
            ]);
        }

        $components = app(FieldFormBuilder::class)->build($definitions);

        return $schema->schema($components);
    }

    public function table(Table $table): Table
    {
        $definitions = $this->loadDefinitions();

        $columns = [
            Tables\Columns\TextColumn::make('display_name')
                ->label('Name')
                ->state(fn (Contact $record): string => $record->name() ?? 'Unnamed')
                ->searchable(false)
                ->sortable(false),
        ];

        foreach ($definitions as $def) {
            $columns[] = Tables\Columns\TextColumn::make("values.{$def->key}")
                ->label($def->label)
                ->state(fn (Contact $record) => $record->value($def->key))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true);
        }

        $columns[] = Tables\Columns\TextColumn::make('updated_at')
            ->label('Updated')
            ->dateTime()
            ->timezone(fn () => auth()->user()?->displayTimezone() ?? config('app.timezone'))
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);

        $hasFields = $definitions->isNotEmpty();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withoutGlobalScope('team'))
            ->recordTitleAttribute('id')
            ->columns($columns)
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add entry')
                    ->disabled(! $hasFields)
                    ->tooltip(! $hasFields ? 'Add at least one field first.' : null)
                    ->using(function (array $data): Contact {
                        /** @var SharedContactList $list */
                        $list = $this->getOwnerRecord();
                        $values = $data['values'] ?? [];

                        return Contact::create([
                            'shared_contact_list_id' => $list->id,
                            'team_id' => null,
                            'values' => $values,
                        ]);
                    }),
                $this->importAction($hasFields),
                $this->smartIngestAction($hasFields),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->label('Delete'),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Load the shared list's active field definitions. Uses the
     * cross-team scope bypass because ContactFieldDefinition uses
     * BelongsToTeamOrSharedPool and super-admins can query across
     * the whole set directly.
     *
     * @return Collection<int, ContactFieldDefinition>
     */
    protected function loadDefinitions(): Collection
    {
        /** @var SharedContactList $list */
        $list = $this->getOwnerRecord();

        return ContactFieldDefinition::query()
            ->withoutGlobalScope('team')
            ->where('shared_contact_list_id', $list->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * CSV / Excel import for shared-list entries. Mirrors the
     * tenant contacts importer but resolves mapping targets from
     * the shared list's own schema and writes rows with
     * `shared_contact_list_id` set instead of `team_id`.
     */
    protected function importAction(bool $hasFields): Actions\Action
    {
        return Actions\Action::make('import')
            ->label('Import CSV / Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->disabled(! $hasFields)
            ->tooltip(! $hasFields ? 'Add at least one field first.' : null)
            ->modalWidth('3xl')
            ->form([
                Forms\Components\FileUpload::make('file')
                    ->label('File')
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->storeFiles(false)
                    ->required()
                    ->live()
                    ->helperText('Upload a .csv or .xlsx file. The first row must be column headers.'),

                \Filament\Schemas\Components\Section::make('Column mapping')
                    ->description('Match each column in your file to one of this list\'s fields. Leave blank to skip a column.')
                    ->visible(fn (Forms\Get $get): bool => filled($get('file')))
                    ->schema(function (Forms\Get $get): array {
                        $file = $get('file');
                        if (! $file) {
                            return [];
                        }

                        $path = $this->resolveUploadPath($file);
                        if ($path === null) {
                            return [];
                        }

                        try {
                            $headers = app(TabularImportService::class)->readHeaders($path);
                        } catch (\Throwable $e) {
                            return [
                                Forms\Components\Placeholder::make('parse_error')
                                    ->content('Unable to read that file: '.$e->getMessage()),
                            ];
                        }

                        /** @var SharedContactList $list */
                        $list = $this->getOwnerRecord();
                        $guesser = app(ColumnMappingGuesser::class);
                        $options = $guesser->optionsForSharedContactList($list->id);

                        $fields = [];
                        foreach ($headers as $header) {
                            $default = $guesser->guessSharedContact($list->id, $header) ?? '';
                            $fields[] = Forms\Components\Select::make("mapping.{$header}")
                                ->label($header)
                                ->options($options)
                                ->default($default)
                                ->native(false);
                        }
                        return $fields;
                    }),
            ])
            ->action(function (array $data): void {
                /** @var SharedContactList $list */
                $list = $this->getOwnerRecord();
                $path = $this->resolveUploadPath($data['file'] ?? null);
                if ($path === null) {
                    Notification::make()->title('No file to import')->danger()->send();
                    return;
                }

                $service = app(TabularImportService::class);
                $rows = $service->readRows($path);
                $mapped = $service->applyMapping($rows, $data['mapping'] ?? []);

                $inserted = 0;
                foreach ($mapped as $row) {
                    if (empty($row)) {
                        continue;
                    }
                    Contact::create([
                        'shared_contact_list_id' => $list->id,
                        'team_id' => null,
                        'values' => $row,
                    ]);
                    $inserted++;
                }

                Notification::make()
                    ->title("Imported {$inserted} entry(s)")
                    ->success()
                    ->send();
            });
    }

    /**
     * Link out to the shared-list smart-ingest page — same
     * ContactSmartIngest Livewire component the tenant version
     * uses, wrapped in a host page that passes the shared list
     * context instead of a team.
     */
    protected function smartIngestAction(bool $hasFields): Actions\Action
    {
        return Actions\Action::make('smart_ingest')
            ->label('Smart ingest (any format)')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->disabled(! $hasFields)
            ->tooltip(! $hasFields ? 'Add at least one field first.' : null)
            ->url(fn (): string => SharedContactListResource::getUrl('smart-ingest', [
                'record' => $this->getOwnerRecord()->id,
            ]));
    }

    /**
     * Filament's FileUpload returns either an UploadedFile (when
     * storeFiles: false) or a string path once stored. Normalize
     * to an absolute path for the importer.
     */
    protected function resolveUploadPath(mixed $file): ?string
    {
        if ($file instanceof UploadedFile) {
            return $file->getRealPath();
        }
        if (is_array($file) && ! empty($file)) {
            $first = reset($file);
            return $this->resolveUploadPath($first);
        }
        if (is_string($file) && is_file($file)) {
            return $file;
        }
        return null;
    }
}
