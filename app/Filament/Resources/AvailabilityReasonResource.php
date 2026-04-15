<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AvailabilityReasonResource\Pages;
use App\Models\AvailabilityReason;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Platform-level CRUD for operator availability reasons.
 *
 * Super-admin only — controls the vocabulary of "I'm not
 * available right now, because __" labels shown in the
 * operator panel topbar selector. Seeded with a starter set
 * (On break, Lunch, In meeting, Training, Offline) via
 * AvailabilityReasonSeeder; the list is fully editable from
 * here at any time.
 *
 * Note: the built-in `available` state is NOT managed here.
 * It's the implicit "taking work" sentinel and always lives
 * at the top of the selector regardless of what the admin
 * puts in this table.
 *
 * The slug column is hidden from the form — it's auto-
 * generated from the label on create and intentionally
 * uneditable afterward so renaming a reason doesn't strand
 * operators currently on that status.
 */
class AvailabilityReasonResource extends Resource
{
    protected static ?string $model = AvailabilityReason::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Features';

    protected static ?string $navigationLabel = 'Availability Reasons';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'label';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('label')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('On break'),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('Short explanation shown as a tooltip.'),
                Forms\Components\ColorPicker::make('dot_color')
                    ->label('Pill dot color')
                    ->default('#f59e0b')
                    ->required()
                    ->helperText('Free-form color picker. The availability selector renders this as the dot next to the operator\'s status label.'),
                Forms\Components\Toggle::make('blocks_new_work')
                    ->label('Block new work')
                    ->default(true)
                    ->helperText('When ON (typical), operators on this status stop receiving new calls and email. Turn OFF for soft statuses where operators should still get new work despite the label.'),
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(50)
                    ->helperText('Lower numbers appear first in the selector dropdown.'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Inactive reasons are hidden from the selector but existing assignments stay intact.'),
            ]);
    }

    /**
     * Auto-slug from label on create, and never touch the slug
     * on edit. The stable key is intentionally not admin-facing
     * — the form doesn't even show it — because renaming would
     * strand operators currently on the reason. Anyone who
     * needs a fundamentally different slug can create a new
     * reason and deactivate the old one.
     */
    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['label'] ?? '', '_');
        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('dot_color')
                    ->label('Color'),
                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('description')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('blocks_new_work')
                    ->label('Blocks work')
                    ->boolean()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->alignCenter()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
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
            'index' => Pages\ListAvailabilityReasons::route('/'),
            'create' => Pages\CreateAvailabilityReason::route('/create'),
            'edit' => Pages\EditAvailabilityReason::route('/{record}/edit'),
        ];
    }
}
