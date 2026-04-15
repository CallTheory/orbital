<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedContactListResource\Pages;

use App\Filament\Resources\SharedContactListResource;
use App\Models\ContactFieldDefinition;
use App\Models\SharedContactList;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Field-schema editor for a shared contact list. Mirrors the
 * per-tenant ManageTenantContactFields page but points at
 * ContactFieldDefinition rows keyed by `shared_contact_list_id`
 * instead of `team_id`. Each shared list owns its own schema so
 * different partner escalation lists can have different columns
 * without colliding with any tenant's private definitions.
 *
 * Role uniqueness is enforced per-shared-list: at most one field
 * on a given list can hold each non-`none` role, so the
 * downstream consumers (name() / email() / phone() on Contact)
 * always find an unambiguous winner.
 */
class ManageSharedContactListFields extends ManageRelatedRecords
{
    protected static string $resource = SharedContactListResource::class;

    protected static string $relationship = 'fieldDefinitions';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Fields';

    protected static ?string $title = 'Shared List Fields';

    public static function getNavigationLabel(): string
    {
        return 'Fields';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Field')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state, ?ContactFieldDefinition $record) {
                                if ($record === null && filled($state)) {
                                    $set('key', Str::slug($state, '_'));
                                }
                            }),
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->maxLength(64)
                            ->helperText('Stable slug used by the JSON column and CSV import. Can\'t be renamed after create.')
                            ->rule('regex:/^[a-z0-9_]+$/')
                            ->validationMessages([
                                'regex' => 'Use only lowercase letters, numbers, and underscores.',
                            ]),
                        Forms\Components\Select::make('type')
                            ->required()
                            ->native(false)
                            ->live()
                            ->options(array_combine(
                                ContactFieldDefinition::TYPES,
                                array_map('ucfirst', ContactFieldDefinition::TYPES),
                            )),
                        Forms\Components\Select::make('role')
                            ->required()
                            ->native(false)
                            ->default('none')
                            ->options([
                                'none' => 'None — just a regular field',
                                'name' => 'Name (used as record title)',
                                'email' => 'Email',
                                'phone' => 'Phone (enables click-to-dial)',
                                'organization' => 'Organization',
                            ])
                            ->helperText('Pick a semantic role so features that need a canonical name/email/phone can find this field.'),
                    ]),

                \Filament\Schemas\Components\Section::make('Options')
                    ->visible(fn (Forms\Get $get): bool => in_array($get('type'), ['select', 'multi_select'], true))
                    ->schema([
                        Forms\Components\Repeater::make('options')
                            ->label('Select choices')
                            ->simple(
                                Forms\Components\TextInput::make('value')->required()->maxLength(255),
                            )
                            ->reorderable()
                            ->defaultItems(0)
                            ->helperText('One value per row. Saved as a flat array.'),
                    ]),

                \Filament\Schemas\Components\Section::make('Display')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('placeholder')->maxLength(255),
                        Forms\Components\TextInput::make('help_text')->maxLength(255),
                        Forms\Components\Toggle::make('required')->default(false),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive fields are hidden from the contact form.'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('label')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('key')
                    ->fontFamily('mono')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'none' ? 'gray' : 'primary'),
                Tables\Columns\IconColumn::make('required')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add field')
                    ->using(function (array $data): ContactFieldDefinition {
                        /** @var SharedContactList $list */
                        $list = $this->getOwnerRecord();
                        $this->guardUniqueRole($list, $data['role'] ?? 'none', null);
                        $data = $this->coerceOptions($data);
                        return ContactFieldDefinition::create([
                            ...$data,
                            'shared_contact_list_id' => $list->id,
                            'team_id' => null,
                        ]);
                    }),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->using(function (ContactFieldDefinition $record, array $data): ContactFieldDefinition {
                        /** @var SharedContactList $list */
                        $list = $this->getOwnerRecord();
                        $this->guardUniqueRole($list, $data['role'] ?? 'none', $record->id);
                        $data = $this->coerceOptions($data);
                        $record->update($data);
                        return $record;
                    }),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Reject save if another field on the same shared list already
     * holds the requested non-`none` role. Same shape as the
     * per-tenant version, just keyed on shared_contact_list_id.
     */
    protected function guardUniqueRole(SharedContactList $list, string $role, ?int $ignoreId): void
    {
        if ($role === 'none') {
            return;
        }

        $existing = ContactFieldDefinition::query()
            ->withoutGlobalScope('team')
            ->where('shared_contact_list_id', $list->id)
            ->where('role', $role)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($existing) {
            Notification::make()
                ->title("Role '{$role}' is already assigned")
                ->body("'{$existing->label}' already plays the {$role} role on this list. Each role can be held by at most one field — change the other field's role first or pick a different role here.")
                ->danger()
                ->persistent()
                ->send();

            throw \Filament\Support\Exceptions\Halt::halt();
        }
    }

    /**
     * Flatten the Repeater-shaped `[['value' => 'foo'], ...]`
     * payload back into a plain array of strings for the Select
     * component to consume at render time.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function coerceOptions(array $data): array
    {
        if (! in_array($data['type'] ?? null, ['select', 'multi_select'], true)) {
            $data['options'] = null;
            return $data;
        }

        $raw = $data['options'] ?? [];
        if (! is_array($raw)) {
            return $data;
        }

        $flat = [];
        foreach ($raw as $row) {
            if (is_string($row)) {
                $flat[] = $row;
            } elseif (is_array($row) && isset($row['value'])) {
                $flat[] = (string) $row['value'];
            }
        }
        $data['options'] = array_values(array_filter($flat, fn ($v) => $v !== ''));
        return $data;
    }
}
