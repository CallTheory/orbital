<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\DirectoryEntry;
use App\Models\DirectoryFieldDefinition;
use App\Models\Team;
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

/**
 * Tenant's own phone book. The people the tenant's business interacts
 * with — employees, patients, clients, members — that AI agents and
 * live operators look up while handling an active call.
 *
 * The form, list columns, import targets, and smart-ingest schema are
 * all driven by DirectoryFieldDefinition rows scoped to this team.
 * Manage the schema via the sibling "Directory Fields" sub-nav page.
 *
 * Never has login accounts attached. No "grant portal access" action
 * here; if a directory entry needs portal access, they should be a
 * Contact, not a directory entry.
 */
class ManageTenantDirectory extends ManageRelatedRecords
{
    protected static string $resource = TenantResource::class;

    protected static string $relationship = 'directoryEntries';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Directory';

    protected static ?string $title = 'Tenant Directory';

    public static function getNavigationLabel(): string
    {
        return 'Directory';
    }

    public function form(Schema $schema): Schema
    {
        $definitions = $this->loadDefinitions();
        $components = app(FieldFormBuilder::class)->build($definitions);

        $components[] = Forms\Components\Select::make('tags')
            ->label('Tags')
            ->multiple()
            ->relationship('tags', 'name', function ($query) {
                $teamId = $this->getOwnerRecord()->id;
                return $query->where(function ($q) use ($teamId) {
                    $q->whereNull('team_id')->orWhere('team_id', $teamId);
                });
            })
            ->preload()
            ->searchable();

        return $schema->schema($components);
    }

    public function table(Table $table): Table
    {
        $definitions = $this->loadDefinitions();

        $columns = [
            Tables\Columns\TextColumn::make('display_name')
                ->label('Name')
                ->state(fn (DirectoryEntry $record): string => $record->fullName())
                ->searchable(false)
                ->sortable(false),
            Tables\Columns\TextColumn::make('tags.name')
                ->label('Tags')
                ->badge()
                ->separator(','),
        ];

        foreach ($definitions as $def) {
            $columns[] = Tables\Columns\TextColumn::make("values.{$def->key}")
                ->label($def->label)
                ->state(fn (DirectoryEntry $record) => $record->value($def->key))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true);
        }

        $columns[] = Tables\Columns\TextColumn::make('updated_at')
            ->label('Updated')
            ->dateTime()
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);

        return $table
            ->recordTitleAttribute('id')
            ->columns($columns)
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add Entry')
                    ->using(function (array $data): DirectoryEntry {
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();
                        $tags = $data['tags'] ?? [];
                        $values = $data['values'] ?? [];

                        $entry = DirectoryEntry::create([
                            'team_id' => $team->id,
                            'values' => $values,
                        ]);

                        if (! empty($tags)) {
                            $entry->tags()->sync($tags);
                        }

                        return $entry;
                    }),
                $this->importAction(),
                $this->smartIngestAction(),
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

    protected function loadDefinitions(): \Illuminate\Support\Collection
    {
        /** @var Team $team */
        $team = $this->getOwnerRecord();

        return DirectoryFieldDefinition::query()
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    protected function importAction(): Actions\Action
    {
        return Actions\Action::make('import')
            ->label('Import CSV / Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
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
                    ->helperText('Upload a .csv or .xlsx file. First row must be column headers.'),

                \Filament\Schemas\Components\Section::make('Column mapping')
                    ->description('Match each column in your file to one of this tenant\'s directory fields. Leave blank to skip a column.')
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

                        $teamId = $this->getOwnerRecord()->id;
                        $guesser = app(ColumnMappingGuesser::class);
                        $options = $guesser->optionsForDirectory($teamId);

                        $fields = [];
                        foreach ($headers as $header) {
                            $default = $guesser->guessDirectory($teamId, $header) ?? '';
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
                /** @var Team $team */
                $team = $this->getOwnerRecord();
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
                    DirectoryEntry::create([
                        'team_id' => $team->id,
                        'values' => $row,
                    ]);
                    $inserted++;
                }

                Notification::make()
                    ->title("Imported {$inserted} directory entries")
                    ->success()
                    ->send();
            });
    }

    protected function smartIngestAction(): Actions\Action
    {
        return Actions\Action::make('smart_ingest')
            ->label('Smart ingest (any format)')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->url(fn (): string => TenantResource::getUrl('smart-ingest', [
                'record' => $this->getOwnerRecord()->id,
                'kind' => 'directory',
            ]));
    }

    protected function resolveUploadPath(mixed $file): ?string
    {
        if ($file instanceof UploadedFile) {
            return $file->getRealPath();
        }
        if (is_array($file) && ! empty($file)) {
            return $this->resolveUploadPath(reset($file));
        }
        if (is_string($file) && is_file($file)) {
            return $file;
        }
        return null;
    }
}
