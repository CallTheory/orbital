<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LogoutReasonResource\Pages;
use App\Models\LogoutReason;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Platform-level CRUD for operator logout reasons.
 *
 * Super-admin only — controls the vocabulary of the "why are
 * you signing out?" selector that intercepts the operator panel
 * logout button. Seeded with a starter set (End of shift, Going
 * home, Taking a break, System issue, Other) via
 * LogoutReasonSeeder; the list is fully editable from here at
 * any time.
 *
 * Intentionally lighter than AvailabilityReasonResource: no dot
 * color and no routing flag, because these only show up in the
 * logout modal and get recorded onto the UserLogoutEvent audit
 * log. A logged-out user isn't on the floor at all — the routing
 * side has nothing to react to.
 */
class LogoutReasonResource extends Resource
{
    protected static ?string $model = LogoutReason::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-left-on-rectangle';

    protected static string|UnitEnum|null $navigationGroup = 'Preferences';

    protected static ?string $navigationLabel = 'Logout Reasons';

    protected static ?int $navigationSort = 40;

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
                    ->placeholder('End of shift'),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('Short explanation shown as a tooltip.'),
                // sort_order is intentionally not in the form —
                // controlled from the list page's drag-to-reorder
                // handles so admins rearrange the whole list visually
                // instead of editing one row's number at a time.
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Inactive reasons are hidden from the logout modal but existing audit entries stay intact.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('description')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            // Drag-to-reorder on `sort_order`. Filament renders a
            // grip handle on each row and writes the new ordering
            // back to the column — the operator logout modal's
            // reason dropdown picks it up on the next render since
            // it queries ordered by `sort_order` ASC.
            ->reorderable('sort_order')
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLogoutReasons::route('/'),
            'create' => Pages\CreateLogoutReason::route('/create'),
            'edit' => Pages\EditLogoutReason::route('/{record}/edit'),
        ];
    }
}
