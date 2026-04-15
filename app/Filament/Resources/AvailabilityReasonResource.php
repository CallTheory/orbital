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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Platform-level CRUD for operator availability reasons.
 *
 * Super-admin only — controls the vocabulary of the states
 * operators can be in, shown in the operator panel topbar
 * selector. Seeded with a starter set (Available, On break,
 * Lunch, In meeting, Training, Offline) via AvailabilityReasonSeeder.
 *
 * The built-in `available` row lives in the same table as every
 * other state but is protected here: the delete action is hidden
 * for it, the `blocks_new_work` and `is_active` toggles are
 * disabled on edit, and the model rejects deletion at the backend
 * as a safety net. Admins CAN rename the Available row, re-color
 * it, or edit the description — they just can't delete it or flip
 * it off, because without it the system has no "accepting work"
 * state and every operator is stranded.
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
                    ->disabled(fn (?Model $record): bool => self::isBuiltInAvailable($record))
                    ->helperText(fn (?Model $record): string => self::isBuiltInAvailable($record)
                        ? 'Locked: the built-in Available row is the one state that must never block work — without it no operator can receive anything.'
                        : 'When ON (typical), operators on this status stop receiving new calls and email. Turn OFF for soft statuses where operators should still get new work despite the label.'),
                // sort_order is intentionally not in the form — it's
                // controlled from the list page's drag-to-reorder
                // handles so admins can see the whole list while they
                // rearrange it, instead of editing one row's number
                // at a time and hoping the others still make sense.
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->disabled(fn (?Model $record): bool => self::isBuiltInAvailable($record))
                    ->helperText(fn (?Model $record): string => self::isBuiltInAvailable($record)
                        ? 'Locked: the built-in Available row is always active. Deactivating it would strand every operator.'
                        : 'Inactive reasons are hidden from the selector but existing assignments stay intact.'),
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

    /**
     * Centralized check for "is this the built-in Available row?"
     * so the form's disabled/helperText closures and the delete
     * action's visibility check all share one rule. Returns false
     * on the create page where $record is null — nothing to protect
     * because the slug constraint would already block creating a
     * second row with the same slug.
     */
    private static function isBuiltInAvailable(?Model $record): bool
    {
        return $record !== null && $record->slug === AvailabilityReason::AVAILABLE;
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
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            // Drag-to-reorder on `sort_order`. Filament renders a
            // grip handle on each row and writes the new ordering
            // back to the column automatically — the AvailabilitySelector
            // dropdown + anywhere else reading AvailabilityReason in
            // `sort_order` ASC picks it up on the next render.
            ->reorderable('sort_order')
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    // No special handling here — the model's
                    // `deleting` hook returns false for the
                    // Available row, which Eloquent treats as
                    // "skip this one, keep going" during a bulk
                    // delete. The admin's other selections still
                    // go through and the Available row quietly
                    // survives.
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
