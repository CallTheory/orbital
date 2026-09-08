<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\ConversationActivity;
use App\Models\MessageQueue;
use App\Models\MessageThread;
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
 * Operator-side message inbox — SMS/MMS and the other text transports.
 *
 * Deliberately the same page as EmailInbox with the channel swapped:
 * same visibility rules (assigned to you, or unclaimed in a queue your
 * agent group works), same claim/unclaim affordance, same bulk close
 * and reopen. An operator working three channels in one shift should
 * not have to learn three interaction models.
 *
 * The one real difference is urgency, and it shows up in the defaults:
 * text conversations are answered in minutes, not hours, so the table
 * polls and the awaiting-reply state is surfaced more loudly than it is
 * for mail.
 */
class MessageInbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?string $navigationLabel = 'Messages';

    protected static ?string $title = 'Message Inbox';

    protected static ?string $slug = 'message-inbox';

    protected string $view = 'filament.operator.pages.message-inbox';

    public function getSubheading(): ?string
    {
        $user = auth()->user();

        if ($user && ! $user->isAvailableForNonVoice()) {
            return 'You are currently unavailable. Switch to available to see and work on conversations.';
        }

        return 'Conversations assigned to you, plus unclaimed ones in queues you work.';
    }

    /**
     * Sidebar badge: open conversations needing this operator. Cached
     * for 15 seconds like the email badge — nav renders shouldn't cost
     * a query each.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $count = Cache::remember(
            "operator:{$user->id}:message_inbox_badge",
            15,
            fn () => MessageThread::query()
                ->whereIn('status', [
                    MessageThread::STATUS_NEW,
                    MessageThread::STATUS_IN_PROGRESS,
                    MessageThread::STATUS_AWAITING_REPLY,
                ])
                ->where(fn ($q) => self::scopeVisibleThreads($q, $user))
                ->count(),
        );

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return auth()->user()?->isAvailableForNonVoice() ? 'primary' : 'gray';
    }

    #[On('availability-updated')]
    public function onAvailabilityUpdated(): void
    {
        $this->bustBadgeCache();
        $this->resetTable();
    }

    public function bustBadgeCache(): void
    {
        Cache::forget('operator:'.auth()->id().':message_inbox_badge');
        $this->dispatch('refresh-sidebar');
    }

    /**
     * Threads visible to this operator:
     *   - assigned to them (always)
     *   - unclaimed with no queue (nobody else owns it)
     *   - unclaimed in an "open" queue (no agent group)
     *   - unclaimed in a queue their agent group works
     *
     * Identical shape to EmailInbox::scopeVisibleThreads. Kept as two
     * methods rather than abstracted: the duplication is a dozen lines
     * and the two channels' visibility rules are free to diverge, which
     * a shared helper would quietly prevent.
     */
    private static function scopeVisibleThreads($query, $user): void
    {
        $myQueueIds = $user->messageQueueIds();

        $openQueueIds = MessageQueue::query()
            ->whereNull('agent_group_id')
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $visibleQueueIds = array_values(array_unique(array_merge($myQueueIds, $openQueueIds)));

        $query->where('assigned_operator_id', $user->id)
            ->orWhere(function ($q) use ($visibleQueueIds) {
                $q->whereNull('assigned_operator_id')
                    ->where(function ($inner) use ($visibleQueueIds) {
                        $inner->whereNull('message_queue_id');

                        if (! empty($visibleQueueIds)) {
                            $inner->orWhereIn('message_queue_id', $visibleQueueIds);
                        }
                    });
            });
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(
                MessageThread::query()
                    ->where(fn ($q) => self::scopeVisibleThreads($q, $user))
                    ->with(['team', 'assignedOperator', 'queue', 'endpoint', 'entries'])
                    ->latest('last_message_at'),
            )
            // Texts are answered in minutes. A stale list is a customer
            // waiting on someone who doesn't know they're there.
            ->poll('20s')
            ->filters([
                TernaryFilter::make('closed')
                    ->label('Include closed')
                    ->placeholder('Open conversations only')
                    ->trueLabel('Include closed')
                    ->falseLabel('Closed only')
                    ->queries(
                        true: fn ($query) => $query,
                        false: fn ($query) => $query->where('status', MessageThread::STATUS_CLOSED),
                        blank: fn ($query) => $query->whereIn('status', [
                            MessageThread::STATUS_NEW,
                            MessageThread::STATUS_IN_PROGRESS,
                            MessageThread::STATUS_AWAITING_REPLY,
                        ]),
                    ),
            ])
            ->searchable()
            ->searchPlaceholder('Search number or message text…')
            ->columns([
                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('Last activity')
                    ->since()
                    ->tooltip(fn (MessageThread $record) => $record->last_message_at
                        ?->timezone(auth()->user()?->displayTimezone() ?? config('app.timezone'))
                        ->format('M j, g:i a T'))
                    ->sortable()
                    ->color('gray')
                    ->url(fn (MessageThread $record) => url("/operator/message-thread/{$record->id}")),

                Tables\Columns\TextColumn::make('remote_address')
                    ->label('From')
                    ->weight(FontWeight::SemiBold)
                    ->searchable(query: function ($query, string $search) {
                        $like = '%'.$search.'%';

                        $query->where(function ($q) use ($like) {
                            $q->where('remote_address', 'ilike', $like)
                                ->orWhereHas('entries', fn ($eq) => $eq->where('body', 'ilike', $like));
                        });
                    })
                    // The preview is what makes a list of phone numbers
                    // usable — an operator triaging ten conversations
                    // needs to see what they're about without opening
                    // each one.
                    ->description(fn (MessageThread $record) => $record->preview())
                    ->url(fn (MessageThread $record) => url("/operator/message-thread/{$record->id}")),

                Tables\Columns\TextColumn::make('team.name')
                    ->label('Client')
                    ->sortable()
                    ->searchable()
                    ->description(fn (MessageThread $record) => $record->endpoint?->address),

                Tables\Columns\TextColumn::make('protocol')
                    ->label('Via')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        MessageThread::STATUS_NEW => 'info',
                        MessageThread::STATUS_IN_PROGRESS => 'primary',
                        MessageThread::STATUS_AWAITING_REPLY => 'warning',
                        MessageThread::STATUS_CLOSED => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('queue.name')
                    ->label('Queue')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('assignedOperator.name')
                    ->label('Claimed by')
                    ->getStateUsing(fn (MessageThread $record) => $record->assigned_operator_id
                        ? $record->assignedOperator?->name
                        : 'Claim')
                    ->badge()
                    ->color(fn (MessageThread $record) => $record->assigned_operator_id ? 'gray' : 'success')
                    ->icon(fn (MessageThread $record) => $record->assigned_operator_id ? null : 'heroicon-m-hand-raised')
                    ->action(function (MessageThread $record) {
                        $userId = auth()->id();

                        if ($record->assigned_operator_id === null) {
                            // claimFor() is a conditional UPDATE, so the
                            // database decides. Two operators clicking
                            // Claim on the same conversation within the
                            // 20s poll interval is an ordinary race, and
                            // the customer must not end up talking to
                            // both — exactly one of them wins here.
                            if (! $record->claimFor(auth()->user())) {
                                Notification::make()
                                    ->title('Already claimed')
                                    ->body('Another operator picked this up first.')
                                    ->warning()
                                    ->send();

                                $this->resetTable();

                                return;
                            }

                            ConversationActivity::log($record, 'claimed', user: auth()->user());
                            $this->bustBadgeCache();
                            Notification::make()->title('Conversation claimed')->success()->send();

                            return;
                        }

                        if ($record->assigned_operator_id === $userId) {
                            $record->release();
                            ConversationActivity::log($record, 'unclaimed', user: auth()->user());
                            $this->bustBadgeCache();
                            Notification::make()->title('Conversation released')->success()->send();
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

                            foreach ($records as $record) {
                                $record->close();
                                ConversationActivity::log($record, 'closed', user: $user);
                            }

                            $this->bustBadgeCache();
                            Notification::make()->title('Conversations closed')->success()->send();
                        }),

                    BulkAction::make('bulk_reopen')
                        ->label('Reopen selected')
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $user = auth()->user();

                            foreach ($records as $record) {
                                $record->forceFill([
                                    'status' => MessageThread::STATUS_IN_PROGRESS,
                                    'closed_at' => null,
                                ])->save();
                                ConversationActivity::log($record, 'reopened', user: $user);
                            }

                            $this->bustBadgeCache();
                            Notification::make()->title('Conversations reopened')->success()->send();
                        }),
                ]),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->emptyStateHeading('No conversations')
            ->emptyStateDescription('Inbound texts for clients you work will appear here.');
    }
}
