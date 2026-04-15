<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Mail\OutboundReplyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Operator-side email inbox.
 *
 * Shows every email thread an operator can work:
 *   - Threads explicitly assigned to the current operator
 *   - Unassigned threads in `new` or `in_progress` status that
 *     haven't been claimed by anyone yet (global pool)
 *
 * Row actions:
 *   - Claim   — set assigned_operator_id to current user
 *   - Open    — navigate into the thread viewer (modal for MVP,
 *               dedicated page in Phase 4)
 *   - Reply   — compose + send a threaded reply
 *   - Close   — mark the thread closed
 *
 * Matches the Workspace page's spot in the Operator panel —
 * lives under a new `Inbox` nav group.
 */
class EmailInbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?string $navigationLabel = 'Email Inbox';

    protected static ?string $title = 'Email Inbox';

    protected static ?string $slug = 'email-inbox';

    protected string $view = 'filament.operator.pages.email-inbox';

    public function getSubheading(): ?string
    {
        return 'Threads assigned to you, plus unclaimed threads in queues you work.';
    }

    /**
     * Sidebar badge count: unclaimed threads in a working state
     * that any operator could pick up. Cached per-user for 15
     * seconds so opening an app page doesn't N-query the DB on
     * every nav render. The 15s window is tight enough that a
     * newly-arrived thread shows up almost immediately without
     * being free.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        // Unavailable operators don't see a count of unclaimed
        // work — the whole point of flipping to on_break / in_meeting
        // / offline is that new work should be invisible. Their
        // own assigned threads are still visible in the inbox
        // body, just not surfaced in the badge.
        if (! $user->isAvailableForWork()) {
            return null;
        }

        $count = \Illuminate\Support\Facades\Cache::remember(
            "operator:{$user->id}:email_inbox_unclaimed",
            15,
            fn () => EmailThread::query()
                ->whereNull('assigned_operator_id')
                ->whereIn('status', [
                    EmailThread::STATUS_NEW,
                    EmailThread::STATUS_IN_PROGRESS,
                    EmailThread::STATUS_AWAITING_REPLY,
                ])
                ->count(),
        );

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $userId = $user?->id;
        $isAvailable = $user?->isAvailableForWork() ?? false;

        return $table
            ->query(
                EmailThread::query()
                    // Always show threads already claimed by this
                    // operator — they can finish whatever they
                    // started even on break. Unassigned threads
                    // only show when the operator is available;
                    // flipping to on_break hides new work without
                    // dropping their in-flight conversations.
                    ->where(function ($q) use ($userId, $isAvailable) {
                        $q->where('assigned_operator_id', $userId);
                        if ($isAvailable) {
                            $q->orWhereNull('assigned_operator_id');
                        }
                    })
                    ->with(['team', 'assignedOperator', 'emailQueue'])
                    ->latest('last_message_at'),
            )
            ->filters([
                TernaryFilter::make('closed')
                    ->label('Include closed')
                    ->placeholder('Open threads only')
                    ->trueLabel('Include closed')
                    ->falseLabel('Closed threads only')
                    ->queries(
                        true: fn ($query) => $query, // no filter — everything
                        false: fn ($query) => $query->where('status', EmailThread::STATUS_CLOSED),
                        blank: fn ($query) => $query->whereIn('status', [
                            EmailThread::STATUS_NEW,
                            EmailThread::STATUS_IN_PROGRESS,
                            EmailThread::STATUS_AWAITING_REPLY,
                        ]),
                    ),
            ])
            ->searchable()
            ->searchPlaceholder('Search subject, sender, body…')
            ->columns([
                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('Last activity')
                    ->dateTime('M j, g:i a')
                    ->timezone(fn () => auth()->user()?->displayTimezone() ?? config('app.timezone'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        EmailThread::STATUS_NEW => 'info',
                        EmailThread::STATUS_IN_PROGRESS => 'primary',
                        EmailThread::STATUS_AWAITING_REPLY => 'warning',
                        EmailThread::STATUS_CLOSED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('team.name')
                    ->label('Tenant')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('emailQueue.name')
                    ->label('Queue')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('subject_root')
                    ->label('Subject')
                    ->wrap()
                    // Full-text scope — also match message body
                    // and sender address, not just the stripped
                    // subject on the thread row itself. Keeps
                    // operators from having to open every thread
                    // to find the one they're looking for.
                    ->searchable(query: function ($query, string $search) {
                        $like = '%'.$search.'%';
                        $query->where(function ($q) use ($like) {
                            $q->where('subject_root', 'ilike', $like)
                              ->orWhereHas('messages', function ($mq) use ($like) {
                                  $mq->where('body_text', 'ilike', $like)
                                     ->orWhere('from_address', 'ilike', $like)
                                     ->orWhere('subject', 'ilike', $like);
                              });
                        });
                    }),
                Tables\Columns\TextColumn::make('assignedOperator.name')
                    ->label('Claimed by')
                    ->placeholder('— unclaimed —'),
            ])
            ->actions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-envelope-open')
                    ->color('gray')
                    ->modalHeading(fn (EmailThread $record) => $record->subject_root ?: '(no subject)')
                    ->modalContent(fn (EmailThread $record) => view('filament.operator.partials.thread-viewer', [
                        'thread' => $record->load(['messages', 'team']),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('4xl'),
                Action::make('claim')
                    ->label('Claim')
                    ->icon('heroicon-m-hand-raised')
                    ->color('primary')
                    ->visible(fn (EmailThread $record) => $record->assigned_operator_id === null)
                    ->action(function (EmailThread $record) {
                        $record->forceFill([
                            'assigned_operator_id' => auth()->id(),
                            'status' => EmailThread::STATUS_IN_PROGRESS,
                        ])->save();
                        Notification::make()
                            ->title('Thread claimed')
                            ->success()
                            ->send();
                    }),
                Action::make('reply')
                    ->label('Reply')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('success')
                    ->schema([
                        Forms\Components\Textarea::make('body_text')
                            ->label('Reply')
                            ->rows(10)
                            ->required()
                            ->placeholder('Write your reply to the customer…'),
                    ])
                    ->modalWidth('3xl')
                    ->action(function (EmailThread $record, array $data) {
                        try {
                            app(OutboundReplyService::class)->reply(
                                thread: $record,
                                bodyText: $data['body_text'],
                                sentByOperator: auth()->user(),
                            );
                            Notification::make()
                                ->title('Reply sent')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Reply failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('close')
                    ->label('Close thread')
                    ->icon('heroicon-m-check-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Mark this thread as handled. It will drop out of the default inbox view.')
                    ->action(function (EmailThread $record) {
                        $record->update(['status' => EmailThread::STATUS_CLOSED]);
                        Notification::make()
                            ->title('Thread closed')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_close')
                        ->label('Close selected')
                        ->icon('heroicon-m-check-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            foreach ($records as $r) {
                                $r->update(['status' => EmailThread::STATUS_CLOSED]);
                            }
                            Notification::make()
                                ->title('Threads closed')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('last_message_at', 'desc');
    }
}
