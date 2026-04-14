<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\IntakeFlowResource\Pages;
use App\Models\IntakeFlow;
use App\Models\IntakeGoal;
use App\Models\Team;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Cross-tenant intake flow editor for super-admins. Flows themselves are
 * tenant-scoped, but super-admins need a single place to browse and
 * author them across every customer. Per-tenant authoring also exists as
 * a child page of TenantResource (ManageTenantFlows).
 */
class IntakeFlowResource extends Resource
{
    protected static ?string $model = IntakeFlow::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static string|UnitEnum|null $navigationGroup = 'Conversational AI';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Intake Flows';

    protected static ?string $pluralModelLabel = 'Intake Flows';

    protected static ?string $modelLabel = 'Intake Flow';

    protected static ?string $slug = 'intake-flows';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Super-admin bypasses the team global scope so every tenant's flows
     * are visible from this single cross-tenant view.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('team');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Flow')
                ->description('Core metadata. The tenant that owns this flow is required and immutable after creation.')
                ->icon('heroicon-o-identification')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('team_id')
                        ->label('Tenant')
                        ->relationship('team', 'name')
                        ->required()
                        ->searchable()
                        ->disabledOn('edit')
                        ->helperText('Flows are tenant-scoped. This can\'t change after creation.'),
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
                ]),

            Section::make('Steps')
                ->description('Ordered sequence of intake goals the agent walks through. Drag to reorder.')
                ->icon('heroicon-o-queue-list')
                ->schema([
                    Forms\Components\Repeater::make('steps')
                        ->hiddenLabel()
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
                        ->cloneable()
                        ->itemLabel(function (array $state): ?string {
                            if (empty($state['intake_goal_id'])) {
                                return null;
                            }
                            return IntakeGoal::withoutGlobalScope('team')
                                ->find($state['intake_goal_id'])?->name;
                        })
                        ->addActionLabel('Add step'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('team.name')
                    ->label('Tenant')
                    ->sortable(),
                Tables\Columns\TextColumn::make('steps_count')
                    ->counts('steps')
                    ->label('Steps')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('trigger_type')
                    ->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Tenant')
                    ->relationship('team', 'name', fn ($query) => $query->where('personal_team', false)),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntakeFlows::route('/'),
            'create' => Pages\CreateIntakeFlow::route('/create'),
            'edit' => Pages\EditIntakeFlow::route('/{record}/edit'),
        ];
    }
}
