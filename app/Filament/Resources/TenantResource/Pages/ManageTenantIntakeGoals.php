<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\IntakeGoal;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Per-tenant view of intake goals — shows tenant-originals and overrides
 * of library goals that belong to this tenant's team.
 *
 * Full structured authoring lives on IntakeGoalResource (platform library).
 * This page is a lightweight way to audit what's scoped to a specific tenant;
 * tenants themselves compose library goals through flows, not here.
 */
class ManageTenantIntakeGoals extends ManageRelatedRecords
{
    protected static string $resource = TenantResource::class;

    protected static string $relationship = 'intakeGoals';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationLabel = 'Intake Goals';

    protected static ?string $title = 'Intake Goals';

    public static function getNavigationLabel(): string
    {
        return 'Intake Goals';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('template_id')
                    ->label('Template')
                    ->options(fn () => IntakeGoal::withoutGlobalScope('team')
                        ->whereNull('team_id')
                        ->whereNull('template_id')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Pick a platform library goal to inherit from. Unchanged fields fall through to the template.'),
                Forms\Components\TextInput::make('key')
                    ->required()
                    ->maxLength(64)
                    ->helperText('Stable slug — used when referencing this goal from a flow.'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->fontFamily('mono')
                    ->size(TextSize::Small)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->headerActions([
                Actions\CreateAction::make(),
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
}
