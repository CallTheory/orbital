<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\Team;
use App\Models\User;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Account-level contacts for a tenant. People the platform operator
 * reaches out to about the tenant's account itself — billing, holiday
 * cards, technical escalations, newsletter opt-ins.
 *
 * The form, list columns, import targets, and smart-ingest schema are
 * **all** driven by ContactFieldDefinition rows scoped to this team
 * — there are no fixed person columns. Manage the schema via the
 * sibling "Contact Fields" sub-nav page.
 *
 * These are NOT users by default. Creating a contact does not create
 * a login account. Use the "Grant portal access" row action when a
 * contact actually needs to sign in; that's what links `contacts.user_id`
 * to a newly-provisioned User with the `tenant_user` role. The action
 * only fires when the tenant has assigned both the `name` and `email`
 * roles to fields — otherwise we don't know what to put in the User
 * row.
 */
class ManageTenantContacts extends ManageRelatedRecords
{
    protected static string $resource = TenantResource::class;

    protected static string $relationship = 'contacts';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Contacts';

    protected static ?string $title = 'Tenant Contacts';

    public static function getNavigationLabel(): string
    {
        return 'Contacts';
    }

    public function form(Schema $schema): Schema
    {
        $definitions = $this->loadDefinitions();
        $components = app(FieldFormBuilder::class)->build($definitions);

        // Append tags after the tenant-defined fields. Tags live in
        // their own pivot table and are not part of the values JSON.
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

        // Always-on columns first.
        $columns = [
            Tables\Columns\TextColumn::make('display_name')
                ->label('Name')
                ->state(fn (Contact $record): string => $record->name() ?? 'Unnamed')
                ->searchable(false)
                ->sortable(false),
            Tables\Columns\TextColumn::make('tags.name')
                ->label('Tags')
                ->badge()
                ->separator(',')
                ->color(fn ($state) => $state ? 'gray' : null),
            Tables\Columns\IconColumn::make('user_id')
                ->label('Portal')
                ->boolean()
                ->tooltip(fn (Contact $record): string => $record->hasPortalAccess()
                    ? 'Has portal login'
                    : 'Notification-only'),
        ];

        // Custom-field columns: registered for every active field
        // definition, hidden by default, operator can toggle on via
        // the column picker. Reads from the values JSON via value().
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

        return $table
            ->recordTitleAttribute('id')
            ->columns($columns)
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add Contact')
                    ->using(function (array $data): Contact {
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();
                        $tags = $data['tags'] ?? [];
                        $values = $data['values'] ?? [];

                        $contact = Contact::create([
                            'team_id' => $team->id,
                            'values' => $values,
                        ]);

                        if (! empty($tags)) {
                            $contact->tags()->sync($tags);
                        }

                        return $contact;
                    }),
                $this->importAction(),
                $this->smartIngestAction(),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\Action::make('grantPortalAccess')
                    ->label('Grant portal access')
                    ->icon('heroicon-o-key')
                    ->color('primary')
                    ->visible(fn (Contact $record): bool =>
                        ! $record->hasPortalAccess()
                        && filled($record->name())
                        && filled($record->email())
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Grant portal login')
                    ->modalDescription(fn (Contact $record) => "A new Orbital account will be created for {$record->email()} with the tenant_user role. A random password will be generated — the contact can reset it via the login page.")
                    ->action(function (Contact $record): void {
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();

                        $user = User::create([
                            'name' => $record->name() ?? 'Tenant user',
                            'email' => $record->email(),
                            'password' => Hash::make(Str::random(32)),
                            'email_verified_at' => now(),
                            'current_team_id' => $team->id,
                        ]);

                        $team->users()->syncWithoutDetaching([$user->id => ['role' => 'member']]);

                        $registrar = app(PermissionRegistrar::class);
                        $original = $registrar->getPermissionsTeamId();
                        $registrar->setPermissionsTeamId($team->id);
                        try {
                            $role = Role::where('team_id', $team->id)
                                ->where('name', 'tenant_user')
                                ->first();
                            if ($role) {
                                $user->assignRole($role);
                            }
                        } finally {
                            $registrar->setPermissionsTeamId($original);
                            $registrar->forgetCachedPermissions();
                        }

                        $record->update(['user_id' => $user->id]);

                        Notification::make()
                            ->title('Portal access granted')
                            ->body("{$record->name()} can now sign in to the portal.")
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('revokePortalAccess')
                    ->label('Revoke portal access')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (Contact $record): bool => $record->hasPortalAccess())
                    ->requiresConfirmation()
                    ->modalHeading('Revoke portal login')
                    ->modalDescription('The login user will be deleted. The contact row is preserved.')
                    ->action(function (Contact $record): void {
                        $user = $record->user;
                        $record->update(['user_id' => null]);
                        $user?->delete();

                        Notification::make()
                            ->title('Portal access revoked')
                            ->success()
                            ->send();
                    }),
                Actions\DeleteAction::make()
                    ->label('Delete'),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Loads the active field definitions for the current tenant. The
     * collection is sorted by sort_order via the relation definition
     * on Team. Used by both form() and table() — call sites cache
     * because Filament re-evaluates form() on every render.
     */
    protected function loadDefinitions(): \Illuminate\Support\Collection
    {
        /** @var Team $team */
        $team = $this->getOwnerRecord();

        return ContactFieldDefinition::query()
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * CSV / Excel import action. The mapping form is rendered after
     * the file upload triggers a re-render — at that point we know
     * the column headers and can offer the tenant's defined fields
     * as mapping targets.
     */
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
                    ->helperText('Upload a .csv or .xlsx file. The first row must be column headers.'),

                \Filament\Schemas\Components\Section::make('Column mapping')
                    ->description('Match each column in your file to one of this tenant\'s contact fields. Leave blank to skip a column.')
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
                        $options = $guesser->optionsForContacts($teamId);

                        $fields = [];
                        foreach ($headers as $header) {
                            $default = $guesser->guessContact($teamId, $header) ?? '';
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
                    Contact::create([
                        'team_id' => $team->id,
                        'values' => $row,
                    ]);
                    $inserted++;
                }

                Notification::make()
                    ->title("Imported {$inserted} contact(s)")
                    ->success()
                    ->send();
            });
    }

    /**
     * Entrypoint into the LLM-backed smart ingest flow. The heavy
     * lifting lives in a dedicated Livewire component
     * (ContactSmartIngest) so the chat-style review UI can stream
     * messages without fighting Filament's form lifecycle.
     */
    protected function smartIngestAction(): Actions\Action
    {
        return Actions\Action::make('smart_ingest')
            ->label('Smart ingest (any format)')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->url(fn (): string => TenantResource::getUrl('smart-ingest', [
                'record' => $this->getOwnerRecord()->id,
                'kind' => 'contacts',
            ]));
    }

    /**
     * Filament's FileUpload component returns either an UploadedFile
     * (when storeFiles: false) or a string path once stored. Normalize
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
