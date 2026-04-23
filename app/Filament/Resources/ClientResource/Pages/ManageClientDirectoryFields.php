<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\DirectoryFieldDefinition;
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
 * Client-side schema editor for the Directory page. Mirror of
 * ManageTenantContactFields with a different model + relationship.
 */
class ManageClientDirectoryFields extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'directoryFieldDefinitions';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Directory Fields';

    protected static ?string $title = 'Directory Field Schema';

    public static function getNavigationLabel(): string
    {
        return 'Directory Fields';
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
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state, ?DirectoryFieldDefinition $record) {
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
                                DirectoryFieldDefinition::TYPES,
                                array_map('ucfirst', DirectoryFieldDefinition::TYPES),
                            )),
                        Forms\Components\Select::make('role')
                            ->required()
                            ->native(false)
                            ->default('none')
                            ->options([
                                'none' => 'None — just a regular field',
                                'name' => 'Name (used as record title)',
                                'email' => 'Email',
                                'phone' => 'Phone (used for click-to-dial)',
                                'organization' => 'Organization',
                            ])
                            ->helperText('Pick a semantic role so call-time features can find this field by purpose, not by slug.'),
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
                            ->helperText('Inactive fields hide from the Directory form and import targets.'),
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
                    ->using(function (array $data): DirectoryFieldDefinition {
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();
                        $this->guardUniqueRole($team, $data['role'] ?? 'none', null);
                        $data = $this->coerceOptions($data);
                        return DirectoryFieldDefinition::create([...$data, 'team_id' => $team->id]);
                    }),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->using(function (DirectoryFieldDefinition $record, array $data): DirectoryFieldDefinition {
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

    protected function guardUniqueRole(Team $team, string $role, ?int $ignoreId): void
    {
        if ($role === 'none') {
            return;
        }

        $existing = DirectoryFieldDefinition::query()
            ->where('team_id', $team->id)
            ->where('role', $role)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($existing) {
            Notification::make()
                ->title("Role '{$role}' is already assigned")
                ->body("'{$existing->label}' already plays the {$role} role for this client. Each role can be held by at most one field — change the other field's role first or pick a different role here.")
                ->danger()
                ->persistent()
                ->send();

            throw \Filament\Support\Exceptions\Halt::halt();
        }
    }

    /**
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
