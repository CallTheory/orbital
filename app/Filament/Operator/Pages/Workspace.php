<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\Message;
use App\Models\Team;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The operator's primary workstation. Becomes tenant-scoped when
 * an account is loaded via "Fetch Account" or an incoming call.
 *
 * When no account is loaded: shows a search prompt to fetch one.
 * When an account is loaded: shows client info, messages table,
 * new message form, and intake flow viewer.
 */
class Workspace extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static string|UnitEnum|null $navigationGroup = 'Workspace';

    protected static ?string $navigationLabel = 'Workspace';

    protected static ?string $title = 'Workspace';

    protected static ?int $navigationSort = -10;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.operator.pages.workspace';

    public ?int $activeTeamId = null;

    public ?array $activeTeamData = null;

    /** @var array<int, array{id: int, name: string, account_number: int|null}> */
    public array $recentAccounts = [];

    /** Inline message form state */
    public bool $takingMessage = false;

    public ?string $msgCallerName = null;

    public ?string $msgCallerPhone = null;

    public ?string $msgReason = null;

    public string $msgUrgency = 'normal';

    public function getSubheading(): ?string
    {
        if ($this->activeTeamData) {
            return 'Account: '.$this->activeTeamData['name'].' (#'.$this->activeTeamData['account_number'].')';
        }

        return 'No account loaded. Use "Fetch Account" to open a client workspace.';
    }

    /**
     * Load a client into the workspace.
     */
    public function fetchAccount(int $teamId): void
    {
        $team = Team::where('personal_team', false)->find($teamId);
        if (! $team) {
            Notification::make()->title('Client not found')->danger()->send();

            return;
        }

        $this->activeTeamId = $team->id;
        $this->activeTeamData = [
            'name' => $team->name,
            'account_number' => $team->account_number,
            'timezone' => $team->displayTimezone(),
        ];

        // Track in recent accounts (most recent first, max 5, no dupes)
        $entry = ['id' => $team->id, 'name' => $team->name, 'account_number' => $team->account_number];
        $this->recentAccounts = collect($this->recentAccounts)
            ->reject(fn ($r) => $r['id'] === $team->id)
            ->prepend($entry)
            ->take(5)
            ->values()
            ->all();

        $this->resetTable();
    }

    /**
     * Quick-fetch from the recent accounts list.
     */
    public function fetchRecentAccount(int $teamId): void
    {
        $this->fetchAccount($teamId);
    }

    /**
     * Enter message-taking mode — replaces the table with a form.
     */
    public function startMessage(): void
    {
        $this->takingMessage = true;
        $this->msgCallerName = null;
        $this->msgCallerPhone = null;
        $this->msgReason = null;
        $this->msgUrgency = 'normal';
    }

    /**
     * Cancel message-taking — return to the table.
     */
    public function cancelMessage(): void
    {
        $this->takingMessage = false;
    }

    /**
     * Save the message and return to the table.
     */
    public function saveMessage(): void
    {
        $this->validate([
            'msgCallerName' => 'required|string|max:255',
            'msgReason' => 'required|string',
        ]);

        Message::create([
            'team_id' => $this->activeTeamId,
            'created_by_user_id' => auth()->id(),
            'caller_name' => $this->msgCallerName,
            'caller_phone' => $this->msgCallerPhone,
            'reason' => $this->msgReason,
            'urgency' => $this->msgUrgency,
            'status' => Message::STATUS_NEW,
        ]);

        $this->takingMessage = false;
        $this->resetTable();
        Notification::make()->title('Message saved')->success()->send();
    }

    /**
     * Release the current client from the workspace.
     */
    public function releaseAccount(): void
    {
        $this->activeTeamId = null;
        $this->activeTeamData = null;
        $this->takingMessage = false;
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch_account')
                ->label('Fetch Account')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->visible(fn () => $this->activeTeamId === null)
                ->modalContent(fn () => ! empty($this->recentAccounts)
                    ? view('filament.operator.partials.recent-accounts', ['accounts' => $this->recentAccounts])
                    : null)
                ->schema([
                    Forms\Components\Select::make('team_id')
                        ->label('Search all clients')
                        ->options(fn () => Team::query()
                            ->where('personal_team', false)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Team $t) => [$t->id => "{$t->name} (#{$t->account_number})"]))
                        ->searchable()
                        ->required(),
                ])
                ->modalWidth('lg')
                ->action(fn (array $data) => $this->fetchAccount((int) $data['team_id'])),

            Action::make('new_message')
                ->label('New Message')
                ->icon('heroicon-o-pencil-square')
                ->color('success')
                ->visible(fn () => $this->activeTeamId !== null && ! $this->takingMessage)
                ->action(fn () => $this->startMessage()),

            Action::make('release')
                ->label('Close Account')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->color('gray')
                ->visible(fn () => $this->activeTeamId !== null)
                ->requiresConfirmation()
                ->modalDescription('Close this account and return to the empty workspace.')
                ->action(fn () => $this->releaseAccount()),
        ];
    }

    /**
     * Messages table — scoped to the active client.
     */
    public function table(Table $table): Table
    {
        $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');

        return $table
            ->query(
                Message::query()
                    ->when($this->activeTeamId, fn ($q) => $q->where('team_id', $this->activeTeamId))
                    ->when(! $this->activeTeamId, fn ($q) => $q->whereRaw('1 = 0'))
                    ->with(['createdByUser', 'agentPersona'])
                    ->latest('created_at'),
            )
            ->heading('Messages')
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Taken')
                    ->dateTime('M j, g:i a')
                    ->timezone($tz)
                    ->sortable(),
                Tables\Columns\TextColumn::make('caller_name')
                    ->label('Caller')
                    ->searchable()
                    ->description(fn (Message $record) => $record->caller_phone),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Message')
                    ->limit(80)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('taken_by')
                    ->label('Taken by')
                    ->getStateUsing(fn (Message $record) => $record->createdByUser?->name ?? $record->agentPersona?->name ?? 'Unknown')
                    ->icon(fn (Message $record) => $record->agent_persona_id ? 'heroicon-o-cpu-chip' : 'heroicon-o-user'),
                Tables\Columns\TextColumn::make('urgency')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Message::URGENCY_URGENT => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Message::STATUS_NEW => 'info',
                        Message::STATUS_READ => 'gray',
                        Message::STATUS_ARCHIVED => 'gray',
                        default => 'gray',
                    }),
            ])
            ->actions([
                Action::make('mark_read')
                    ->label('Mark Read')
                    ->icon('heroicon-m-check')
                    ->color('gray')
                    ->visible(fn (Message $record) => $record->status === Message::STATUS_NEW)
                    ->action(fn (Message $record) => $record->update(['status' => Message::STATUS_READ])),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-m-archive-box')
                    ->color('gray')
                    ->visible(fn (Message $record) => $record->status !== Message::STATUS_ARCHIVED)
                    ->action(fn (Message $record) => $record->update(['status' => Message::STATUS_ARCHIVED])),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('archived')
                    ->placeholder('Active messages')
                    ->trueLabel('Include archived')
                    ->falseLabel('Archived only')
                    ->queries(
                        true: fn ($q) => $q,
                        false: fn ($q) => $q->where('status', Message::STATUS_ARCHIVED),
                        blank: fn ($q) => $q->where('status', '!=', Message::STATUS_ARCHIVED),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(fn () => $this->activeTeamId ? 'No messages yet' : 'No account loaded')
            ->emptyStateDescription(fn () => $this->activeTeamId
                ? 'Messages taken for this client will appear here.'
                : 'Fetch an account to view its messages.');
    }
}
