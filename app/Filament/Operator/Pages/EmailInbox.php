<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\ConversationActivity;
use App\Models\EmailQueue;
use App\Models\EmailThread;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
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
        $user = auth()->user();
        if ($user && ! $user->isAvailableForWork()) {
            return 'You are currently unavailable. Switch to available to see and work on threads.';
        }

        return 'Threads assigned to you, plus unclaimed threads in queues you work.';
    }

    /**
     * Sidebar badge count: open threads that need this operator's
     * attention — anything assigned to them that isn't closed, plus
     * unclaimed threads (when available for work). Matches the
     * same scope as the table query so the number always reflects
     * what they'll see when they click in.
     *
     * Cached per-user for 15 seconds so nav renders don't hit the
     * DB on every page load.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $count = Cache::remember(
            "operator:{$user->id}:email_inbox_badge",
            15,
            function () use ($user) {
                $openStatuses = [
                    EmailThread::STATUS_NEW,
                    EmailThread::STATUS_IN_PROGRESS,
                    EmailThread::STATUS_AWAITING_REPLY,
                ];

                return EmailThread::query()
                    ->whereIn('status', $openStatuses)
                    ->where(fn ($q) => self::scopeVisibleThreads($q, $user))
                    ->count();
            },
        );

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $user = auth()->user();

        return $user?->isAvailableForWork() ? 'primary' : 'gray';
    }

    /**
     * When the operator toggles availability via the topbar
     * selector, refresh the table (which re-evaluates the
     * isAvailable gate on unclaimed threads) and the sidebar
     * badge (which flips color between primary and gray).
     */
    #[On('availability-updated')]
    public function onAvailabilityUpdated(): void
    {
        $this->bustBadgeCache();
        $this->resetTable();
    }

    /**
     * Bust the cached badge count and refresh the sidebar so the
     * nav badge updates immediately after state-changing actions
     * without a full page reload.
     */
    public function bustBadgeCache(): void
    {
        Cache::forget('operator:'.auth()->id().':email_inbox_badge');
        $this->dispatch('refresh-sidebar');
    }

    /**
     * Scope a query to threads visible to this operator:
     *   - Threads assigned to them (always)
     *   - Unrouted threads with no queue (everyone sees)
     *   - Threads in "open" queues with no agent group (everyone sees)
     *   - Threads in queues the operator belongs to via AgentGroup
     */
    private static function scopeVisibleThreads($query, $user): void
    {
        $userId = $user->id;
        $myQueueIds = $user->emailQueueIds();

        // IDs of "open" queues (no agent group restriction).
        $openQueueIds = EmailQueue::query()
            ->whereNull('agent_group_id')
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $visibleQueueIds = array_values(array_unique(array_merge($myQueueIds, $openQueueIds)));

        $query->where('assigned_operator_id', $userId)
            ->orWhere(function ($q) use ($visibleQueueIds) {
                $q->whereNull('assigned_operator_id')
                    ->where(function ($inner) use ($visibleQueueIds) {
                        $inner->whereNull('email_queue_id');
                        if (! empty($visibleQueueIds)) {
                            $inner->orWhereIn('email_queue_id', $visibleQueueIds);
                        }
                    });
            });
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $userId = $user?->id;

        return $table
            ->query(
                EmailThread::query()
                    ->where(fn ($q) => self::scopeVisibleThreads($q, $user))
                    ->with(['team', 'assignedOperator', 'emailQueue', 'messages'])
                    ->latest('last_message_at'),
            )
            ->filters([
                TernaryFilter::make('closed')
                    ->label('Include closed')
                    ->placeholder('Open threads only')
                    ->trueLabel('Include closed')
                    ->falseLabel('Closed threads only')
                    ->queries(
                        true: fn ($query) => $query,
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
                    ->label('Received')
                    ->dateTime('M j, g:i a T')
                    ->timezone(fn () => auth()->user()?->displayTimezone() ?? config('app.timezone'))
                    ->sortable()
                    ->color('gray')
                    ->url(fn (EmailThread $record) => url("/operator/email-thread/{$record->id}")),
                Tables\Columns\TextColumn::make('subject_root')
                    ->label('Subject')
                    ->weight(FontWeight::SemiBold)
                    ->wrap()
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
                    })
                    ->url(fn (EmailThread $record) => url("/operator/email-thread/{$record->id}")),
                Tables\Columns\TextColumn::make('team.name')
                    ->label('Tenant')
                    ->sortable()
                    ->searchable()
                    ->description(function (EmailThread $record) {
                        $first = $record->messages->first(fn ($m) => $m->direction === 'inbound');
                        if (! $first) {
                            return null;
                        }

                        return implode(', ', $first->metadata['envelope_to'] ?? $first->to_addresses ?? []);
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        EmailThread::STATUS_NEW => 'info',
                        EmailThread::STATUS_IN_PROGRESS => 'primary',
                        EmailThread::STATUS_AWAITING_REPLY => 'warning',
                        EmailThread::STATUS_CLOSED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('emailQueue.name')
                    ->label('Queue')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('assignedOperator.name')
                    ->label('Claimed by')
                    ->getStateUsing(fn (EmailThread $record) => $record->assigned_operator_id
                        ? $record->assignedOperator?->name
                        : 'Claim')
                    ->badge()
                    ->color(fn (EmailThread $record) => $record->assigned_operator_id ? 'gray' : 'success')
                    ->icon(fn (EmailThread $record) => $record->assigned_operator_id ? null : 'heroicon-m-hand-raised')
                    ->action(function (EmailThread $record) {
                        if ($record->assigned_operator_id === null) {
                            // Claim
                            $record->forceFill([
                                'assigned_operator_id' => auth()->id(),
                                'status' => EmailThread::STATUS_IN_PROGRESS,
                            ])->save();
                            ConversationActivity::log($record, 'claimed', user: auth()->user());
                            $this->bustBadgeCache();
                            Notification::make()->title('Thread claimed')->success()->send();
                        } elseif ($record->assigned_operator_id === auth()->id()) {
                            // Unclaim — own thread only
                            $record->forceFill([
                                'assigned_operator_id' => null,
                                'status' => EmailThread::STATUS_NEW,
                            ])->save();
                            ConversationActivity::log($record, 'unclaimed', user: auth()->user());
                            $this->bustBadgeCache();
                            Notification::make()->title('Thread unclaimed')->success()->send();
                        }
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
                        ->action(function (Collection $records) {
                            $user = auth()->user();
                            foreach ($records as $r) {
                                $r->update(['status' => EmailThread::STATUS_CLOSED]);
                                ConversationActivity::log($r, 'closed', user: $user);
                            }
                            $this->bustBadgeCache();
                            Notification::make()
                                ->title('Threads closed')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('bulk_reopen')
                        ->label('Reopen selected')
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $user = auth()->user();
                            foreach ($records as $r) {
                                $r->update(['status' => EmailThread::STATUS_IN_PROGRESS]);
                                ConversationActivity::log($r, 'reopened', user: $user);
                            }
                            $this->bustBadgeCache();
                            Notification::make()
                                ->title('Threads reopened')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('last_message_at', 'desc');
    }
}
