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
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Per-tenant intake flow manager. Same form as IntakeFlowResource minus
 * the tenant picker (tenant is fixed by the route parent).
 */
class ManageTenantFlows extends ManageRelatedRecords
{
    protected static string $resource = TenantResource::class;

    protected static string $relationship = 'intakeFlows';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = 'Intake Flows';

    protected static ?string $title = 'Intake Flows';

    public static function getNavigationLabel(): string
    {
        return 'Intake Flows';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->placeholder('Main intake flow'),
            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->columnSpanFull(),
            Forms\Components\Select::make('trigger_type')
                ->options([
                    'persona_default' => 'Persona default',
                    'did' => 'Specific DID',
                    'routing_rule' => 'Routing rule',
                    'manual' => 'Manually assigned',
                ])
                ->default('persona_default')
                ->native(false),
            Forms\Components\Toggle::make('is_active')
                ->default(true),

            Forms\Components\Repeater::make('steps')
                ->relationship('steps')
                ->schema([
                    Forms\Components\Select::make('intake_goal_id')
                        ->label('Goal')
                        ->options(fn () => IntakeGoal::withoutGlobalScope('team')
                            ->whereNull('team_id')
                            ->whereNull('template_id')
                            ->where('is_active', true)
                            ->orderBy('category')
                            ->orderBy('name')
                            ->get()
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->columnSpan(2),
                    Forms\Components\Toggle::make('is_required')
                        ->default(true)
                        ->inline(false),
                ])
                ->columns(3)
                ->reorderable()
                ->orderColumn('position')
                ->collapsible()
                ->itemLabel(function (array $state): ?string {
                    if (empty($state['intake_goal_id'])) {
                        return null;
                    }
                    return IntakeGoal::withoutGlobalScope('team')
                        ->find($state['intake_goal_id'])?->name;
                })
                ->columnSpanFull()
                ->addActionLabel('Add step'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('steps_count')
                    ->counts('steps')
                    ->label('Steps')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('trigger_type')->badge(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
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
