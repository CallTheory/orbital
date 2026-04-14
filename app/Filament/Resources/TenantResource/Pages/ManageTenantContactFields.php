<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\ContactFieldDefinition;
use App\Models\Team;
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
 * Tenant-side schema editor for the Contacts page. The operator
 * defines the fields here; the Contacts page renders a form built
 * from whatever's defined.
 *
 * Role validation: at most one field per team can hold each non-`none`
 * role (name / email / phone / organization). The Filament form
 * enforces this on save by rejecting a duplicate role assignment with
 * an inline error.
 */
class ManageTenantContactFields extends ManageRelatedRecords
{
    protected static string $resource = TenantResource::class;

    protected static string $relationship = 'contactFieldDefinitions';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Contact Fields';

    protected static ?string $title = 'Contact Field Schema';

    public static function getNavigationLabel(): string
    {
        return 'Contact Fields';
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
                                // Auto-populate the slug on label edit
                                // when creating, but never overwrite an
                                // existing slug — operators may rely
                                // on the slug staying stable.
                                if ($record === null && filled($state)) {
                                    $set('key', Str::slug($state, '_'));
                                }
                            }),
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->maxLength(64)
                            ->helperText('Stable slug used by the JSON column, CSV import, and smart ingest.')
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
                                'email' => 'Email (enables Grant portal access)',
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
                            ->helperText('Inactive fields hide from the Contacts form and import targets.'),
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
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();
                        $this->guardUniqueRole($team, $data['role'] ?? 'none', null);
                        $data = $this->coerceOptions($data);
                        return ContactFieldDefinition::create([...$data, 'team_id' => $team->id]);
                    }),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->using(function (ContactFieldDefinition $record, array $data): ContactFieldDefinition {
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();
                        $this->guardUniqueRole($team, $data['role'] ?? 'none', $record->id);
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
     * Reject save if another field on the same team already holds
     * the requested non-`none` role. Surfaces a Filament notification
     * + halts the action via a validation exception.
     */
    protected function guardUniqueRole(Team $team, string $role, ?int $ignoreId): void
    {
        if ($role === 'none') {
            return;
        }

        $existing = ContactFieldDefinition::query()
            ->where('team_id', $team->id)
            ->where('role', $role)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($existing) {
            Notification::make()
                ->title("Role '{$role}' is already assigned")
                ->body("'{$existing->label}' already plays the {$role} role for this tenant. Each role can be held by at most one field — change the other field's role first or pick a different role here.")
                ->danger()
                ->persistent()
                ->send();

            throw \Filament\Support\Exceptions\Halt::halt();
        }
    }

    /**
     * The Repeater stores options as `[['value' => 'foo'], …]`. Flatten
     * back to a plain array of strings before persisting so the
     * Select component can consume them directly.
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
