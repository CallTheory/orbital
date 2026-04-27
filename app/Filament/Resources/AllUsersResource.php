<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AllUsersResource\Pages;
use App\Models\Team;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Unified admin view of every User in the system — platform staff,
 * client portal users, and staff assigned as account managers.
 *
 * Complements:
 *   - **Staff** (UsersResource, slug `staff`) — filtered to team-less
 *     platform-role holders only; has the softphone provisioning UI.
 *   - **Clients → Contacts** — per-client view of that client's people.
 *
 * This page is the cross-cutting "who has a login account anywhere"
 * audit surface. Edits here touch the User row directly (name, email,
 * password reset); role and client-membership changes happen via the
 * originating surfaces (Staff or Clients → Contacts).
 */
class AllUsersResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Users';

    protected static ?string $slug = 'users';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * "Users" means client-associated logins with NO platform role.
     * Staff lives in its own admin page (UsersResource, slug
     * `staff`). Filtering them out here keeps the two surfaces
     * strictly non-overlapping so the nav entries mean what they
     * say — no "why is this staff member showing up in Users?".
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            // Attached to at least one client via Jetstream's
            // team_user pivot — the definition of a client user.
            ->whereExists(function ($q) {
                $q->select(\DB::raw(1))
                    ->from('team_user')
                    ->whereColumn('team_user.user_id', 'users.id');
            })
            // Not a platform staff user — staff gets its own page.
            ->whereNotExists(function ($q) {
                $q->select(\DB::raw(1))
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', User::class)
                    ->whereNull('model_has_roles.team_id');
            });
    }

    /**
     * Does $user carry any team-less (platform-level) role? Used by
     * EditAllUser::mount() to redirect staff user IDs over to
     * UsersResource (slug `staff`) so `/admin/users/{staff_id}/edit`
     * doesn't 404 when the operator stumbles on a staff user ID.
     */
    public static function isStaffUser(User $user): bool
    {
        return \DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->whereNull('team_id')
            ->exists();
    }

    public static function canCreate(): bool
    {
        return false;  // creation happens via Staff / Client invite / etc.
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            // Three-column grid: Account + Audit stack in the left
            // two-thirds, Client memberships owns the right third so
            // it can grow as the user gains client rows without
            // pushing Audit further down the page. `columnSpanFull`
            // is load-bearing here — without it, the Grid renders
            // inside a single column unit of the form's outer schema
            // and ends up pinned to ~50% of the page width.
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Group::make([
                        Section::make('Account')
                            ->schema([
                                // Password is intentionally NOT editable here.
                                // Changing a password always goes through the
                                // Actions dropdown (Generate new password /
                                // Email reset link) so a super-admin can't
                                // accidentally set a weak one by hand.
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255),
                            ])
                            ->columns(2),

                        Section::make('Audit')
                            ->schema([
                                // Disabled TextInputs instead of Placeholders
                                // so the read-only metadata sits visually
                                // flush with the editable fields above —
                                // same border, same labels, same spacing.
                                // dehydrated(false) keeps these out of the
                                // save payload entirely.
                                Forms\Components\TextInput::make('id')
                                    ->label('User ID')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn (User $record) => (string) $record->id),
                                Forms\Components\TextInput::make('created_at_display')
                                    ->label('Created')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn (User $record) => $record->created_at?->diffForHumans() ?? '—'),
                                Forms\Components\TextInput::make('email_verified_at_display')
                                    ->label('Email verified')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn (User $record) => $record->email_verified_at?->diffForHumans() ?? 'Not verified'),
                                Forms\Components\TextInput::make('two_factor_display')
                                    ->label('2FA')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn (User $record) => $record->two_factor_confirmed_at ? 'Enabled' : 'Off'),
                            ])
                            ->columns(2)
                            ->collapsible(),
                    ])
                        ->columnSpan(2),

                    Section::make('Client memberships')
                        ->description('Clients this user is attached to.')
                        ->schema([
                            Forms\Components\Placeholder::make('clients')
                                ->hiddenLabel()
                                ->content(function (User $record) {
                                    // Exclude Jetstream personal teams —
                                    // keeps the list to actual customer
                                    // clients only, not Platform / per-user
                                    // personal teams.
                                    $clients = Team::query()
                                        ->where('personal_team', false)
                                        ->whereIn('id', \DB::table('team_user')
                                            ->where('user_id', $record->id)
                                            ->pluck('team_id'))
                                        ->orderBy('name')
                                        ->get(['id', 'name', 'account_number']);

                                    if ($clients->isEmpty()) {
                                        return new HtmlString(
                                            '<span class="text-sm text-gray-500 dark:text-gray-400">Not attached to any client.</span>'
                                        );
                                    }

                                    $rows = $clients->map(function ($t) {
                                        $name = e($t->name);
                                        $acct = $t->account_number
                                            ? ' <span class="text-gray-500 dark:text-gray-400">· #'.e($t->account_number).'</span>'
                                            : '';
                                        $url = ClientResource::getUrl('users', ['record' => $t->id]);

                                        // Inline `display: list-item` + the
                                        // margin on the <ul> bypass Filament's
                                        // global list reset, which neuters
                                        // Tailwind's list-disc utility.
                                        return '<li style="display: list-item; list-style-type: disc;"><a href="'.$url.'" class="text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a>'.$acct.'</li>';
                                    })->implode('');

                                    return new HtmlString(
                                        '<ul style="list-style: disc; padding-left: 1.25rem;" class="space-y-4 text-sm">'.$rows.'</ul>'
                                    );
                                }),
                        ])
                        ->columnSpan(1),
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
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                // One client per line so wide client membership doesn't
                // smear across the row. Driven by the team_user pivot.
                Tables\Columns\TextColumn::make('tenants_list')
                    ->label('Clients')
                    ->state(fn (User $record): array => $record->allTeams()
                        ->sortBy('name')
                        ->pluck('name')
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereExists(function ($q) use ($search) {
                            $q->select(\DB::raw(1))
                                ->from('team_user')
                                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                                ->whereColumn('team_user.user_id', 'users.id')
                                ->where('teams.name', 'ilike', "%{$search}%");
                        });
                    }),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->state(fn (User $record): bool => $record->email_verified_at !== null)
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->state(fn (User $record): bool => $record->two_factor_confirmed_at !== null)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (User $record): string => $record->two_factor_confirmed_at
                        ? 'Two-factor authentication is enabled.'
                        : 'Two-factor authentication has not been set up yet.')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Kind filter dropped — the query already restricts to
            // client users only. Filter by client (membership) via
            // the table search (client name is searchable on the
            // Clients column).
            // No per-row actions column — clicking the row opens the
            // edit page instead. Keeps the table clean and makes the
            // Name and Email columns (the obvious click targets) do
            // the obvious thing.
            ->recordUrl(fn (User $record): string => static::getUrl('edit', ['record' => $record]))
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    // classifyUser() removed — the scoped query on this page
    // already excludes everyone but client portal users, so no
    // per-row classification is needed. ContactClassifier is the
    // canonical place when a per-row badge is needed elsewhere.

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAllUsers::route('/'),
            'edit' => Pages\EditAllUser::route('/{record}/edit'),
        ];
    }
}
