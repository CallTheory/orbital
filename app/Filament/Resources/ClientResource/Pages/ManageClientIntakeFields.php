<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\ClientSlot;
use App\Models\Team;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * The per-client fields the AI collects on a call (ClientSlots). Their
 * values land in the call session and are returned by the handoff API.
 */
class ManageClientIntakeFields extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'slots';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Intake Fields';

    protected static ?string $title = 'Intake Fields';

    public static function getNavigationLabel(): string
    {
        return 'Intake Fields';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(64)
                    ->helperText('Machine key: lowercase letters, numbers, underscores.')
                    ->rule('regex:/^[a-z0-9_]+$/')
                    ->validationMessages([
                        'regex' => 'Use only lowercase letters, numbers, and underscores.',
                    ])
                    ->unique(
                        table: 'client_slots',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('team_id', $this->getOwnerRecord()->getKey()),
                    ),
                Forms\Components\TextInput::make('label')
                    ->maxLength(255)
                    ->helperText('Shown to the live operator. Defaults to the key, title-cased.'),
                Forms\Components\Select::make('type')
                    ->required()
                    ->native(false)
                    ->live()
                    ->default('string')
                    ->options(array_combine(ClientSlot::TYPES, array_map('ucfirst', ClientSlot::TYPES))),
                Forms\Components\TagsInput::make('choices')
                    ->visible(fn (Get $get): bool => $get('type') === 'choice')
                    ->required(fn (Get $get): bool => $get('type') === 'choice'),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull()
                    ->rows(2)
                    ->helperText('Tells the AI what to ask for.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('name')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('label')->placeholder('(auto)'),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('description')->limit(60)->color('gray'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add field')
                    ->using(function (array $data): ClientSlot {
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();

                        return ClientSlot::create([...$this->clean($data), 'team_id' => $team->id]);
                    }),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->using(function (ClientSlot $record, array $data): ClientSlot {
                        $record->update($this->clean($data));

                        return $record;
                    }),
                Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Choices only apply to the choice type.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function clean(array $data): array
    {
        if (($data['type'] ?? null) !== 'choice') {
            $data['choices'] = null;
        } else {
            $data['choices'] = array_values($data['choices'] ?? []);
        }
        $data['label'] = filled($data['label'] ?? null) ? $data['label'] : null;

        return $data;
    }
}
