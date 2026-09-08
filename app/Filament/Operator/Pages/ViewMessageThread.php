<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\ConversationActivity;
use App\Models\Message;
use App\Models\MessageThread;
use App\Services\Messages\PartialMessagePolicy;
use App\Services\Messaging\OptOutRegistry;
use App\Services\Messaging\OutboundMessageService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Full-page conversation view for a single message thread.
 *
 * Navigated to from MessageInbox via an SPA link — no full page reload,
 * because the operator's softphone lives in the same window and a
 * reload would drop an active call.
 *
 * The reply box is inline rather than a modal. Texting is a fast
 * back-and-forth; making an operator open a dialog for every two-word
 * answer would make this the slowest channel to work when it should be
 * the quickest.
 */
class ViewMessageThread extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'message-thread/{threadId}';

    protected string $view = 'filament.operator.pages.view-message-thread';

    public ?MessageThread $thread = null;

    /** Inline reply box state. */
    public string $replyBody = '';

    public function mount(int $threadId): void
    {
        $this->thread = MessageThread::with([
            'team', 'queue', 'assignedOperator', 'endpoint',
            'entries.sentByUser', 'entries.agentPersona',
        ])->findOrFail($threadId);

        static::$title = $this->thread->remote_address;
    }

    public function getSubheading(): ?string
    {
        $parts = ['Client: '.$this->thread->team?->name];

        if ($this->thread->endpoint) {
            $parts[] = 'On: '.$this->thread->endpoint->address;
        }

        if ($this->thread->queue) {
            $parts[] = 'Queue: '.$this->thread->queue->name;
        }

        if ($this->thread->assignedOperator) {
            $parts[] = 'Claimed by: '.$this->thread->assignedOperator->name;
        }

        return implode(' · ', array_filter($parts));
    }

    /**
     * Can the current operator send on this thread?
     *
     * Claiming is required before replying. Two operators answering the
     * same customer independently is worse than a slow reply, because
     * the customer sees both and neither knows what the other said.
     *
     * An opted-out recipient can't be replied to at all, by anyone. The
     * send would be blocked downstream regardless; refusing here means
     * the operator finds out before they type the message rather than
     * after.
     */
    public function canReply(): bool
    {
        return $this->thread?->assigned_operator_id === auth()->id()
            && ! $this->isSuppressed();
    }

    /**
     * Has this customer told the client to stop texting them?
     *
     * Read on render, not cached on the model, so an operator with the
     * thread open when the STOP arrives sees the reply box close on the
     * next poll instead of sending into a suppressed number.
     */
    public function isSuppressed(): bool
    {
        if (! $this->thread) {
            return false;
        }

        return app(OptOutRegistry::class)->isThreadSuppressed($this->thread);
    }

    public function claim(): void
    {
        if (! $this->thread->claimFor(auth()->user())) {
            Notification::make()
                ->title('Already claimed')
                ->body('Another operator picked this up first.')
                ->warning()
                ->send();

            $this->refreshThread();

            return;
        }

        ConversationActivity::log($this->thread, 'claimed', user: auth()->user());
        $this->bustBadgeCache();
        $this->refreshThread();

        Notification::make()->title('Conversation claimed')->success()->send();
    }

    public function release(): void
    {
        if ($this->thread->assigned_operator_id !== auth()->id()) {
            return;
        }

        $this->thread->release();
        ConversationActivity::log($this->thread, 'unclaimed', user: auth()->user());
        $this->bustBadgeCache();
        $this->refreshThread();

        Notification::make()->title('Conversation released')->success()->send();
    }

    public function sendReply(OutboundMessageService $outbound): void
    {
        $body = trim($this->replyBody);

        if ($body === '') {
            return;
        }

        if ($this->isSuppressed()) {
            Notification::make()
                ->title('This person has opted out')
                ->body('They asked this client to stop texting them, so nothing can be sent on this conversation. They can opt back in by texting START.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if (! $this->canReply()) {
            Notification::make()
                ->title('Claim this conversation first')
                ->body('Only the operator handling a conversation can reply to it.')
                ->warning()
                ->send();

            return;
        }

        $entry = $outbound->replyAsOperator($this->thread, auth()->user(), $body);

        $this->replyBody = '';
        ConversationActivity::log($this->thread, 'replied', user: auth()->user());
        $this->bustBadgeCache();
        $this->refreshThread();

        // A send that the provider refused outright is reported to the
        // operator immediately and loudly. The alternative — a silent
        // failure — leaves them believing the customer was told
        // something they were never told.
        if ($entry->failedToDeliver()) {
            Notification::make()
                ->title('Message not sent')
                ->body($entry->delivery_error ?: 'The provider rejected this message.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title('Sent')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('claim')
                ->label('Claim')
                ->icon('heroicon-o-hand-raised')
                ->color('success')
                ->visible(fn (): bool => $this->thread?->assigned_operator_id === null)
                ->action('claim'),

            Action::make('release')
                ->label('Release')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->thread?->assigned_operator_id === auth()->id())
                ->action('release'),

            Action::make('close')
                ->label('Close')
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->thread?->status !== MessageThread::STATUS_CLOSED)
                ->requiresConfirmation()
                ->modalDescription('A new message from this number within the next few days will reopen the conversation automatically.')
                ->action(function (): void {
                    $this->thread->close();
                    ConversationActivity::log($this->thread, 'closed', user: auth()->user());
                    $this->bustBadgeCache();
                    $this->refreshThread();
                    Notification::make()->title('Conversation closed')->success()->send();
                }),

            Action::make('reopen')
                ->label('Reopen')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->thread?->status === MessageThread::STATUS_CLOSED)
                ->action(function (): void {
                    $this->thread->forceFill([
                        'status' => MessageThread::STATUS_IN_PROGRESS,
                        'closed_at' => null,
                    ])->save();
                    ConversationActivity::log($this->thread, 'reopened', user: auth()->user());
                    $this->bustBadgeCache();
                    $this->refreshThread();
                }),

            Action::make('takeMessage')
                ->label('Take message')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->visible(fn (): bool => $this->thread?->assigned_operator_id === auth()->id())
                ->modalHeading('Take a message from this conversation')
                ->modalDescription(function (): string {
                    $base = 'Writes an answering-service message into the client\'s portal. Fill in what the customer actually gave you — if the reason never arrived and this client keeps partials, it is saved marked incomplete rather than discarded.';

                    // A conversation can legitimately produce several
                    // messages, so this warns rather than blocks — but an
                    // operator picking up a thread mid-shift should know
                    // somebody already wrote it up.
                    $taken = $this->thread?->messages()->count() ?? 0;

                    return $taken === 0
                        ? $base
                        : $base.' '.trans_choice(
                            '{1}One message has already been taken from this conversation.|[2,*]:count messages have already been taken from this conversation.',
                            $taken,
                            ['count' => $taken],
                        );
                })
                ->modalSubmitActionLabel('Save message')
                ->fillForm(fn (): array => [
                    // The callback number is the one thing this channel
                    // always knows. On a call an operator has to ask for
                    // it and can mishear it; here it is the address the
                    // customer texted from.
                    'caller_phone' => $this->thread?->remote_address,
                    'urgency' => Message::URGENCY_NORMAL,
                ])
                ->schema([
                    Forms\Components\TextInput::make('caller_name')
                        ->label('Caller name')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('caller_phone')
                        ->label('Callback number')
                        ->maxLength(50)
                        ->helperText('Prefilled from the number they texted from.'),
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason for the message')
                        ->rows(4),
                    Forms\Components\Select::make('urgency')
                        ->label('Urgency')
                        ->options([
                            Message::URGENCY_NORMAL => 'Normal',
                            Message::URGENCY_URGENT => 'Urgent',
                        ])
                        ->default(Message::URGENCY_NORMAL)
                        ->selectablePlaceholder(false),
                ])
                ->action(function (array $data): void {
                    $this->takeMessage($data);
                }),

            Action::make('back')
                ->label('Back to inbox')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => url('/operator/message-inbox')),
        ];
    }

    /**
     * Write this conversation up as an answering-service message.
     *
     * The messaging channel had no way to do this: an operator could
     * text a customer all day and nothing ever reached the client's
     * portal. Same destination as the voice path — one `messages` row
     * the client reads — with the conversation recorded on it so the
     * message can be traced back to what was actually said.
     *
     * Complete versus partial is decided here rather than by which
     * button the operator pressed, because the rule is the client's
     * policy, not the operator's judgement. Name plus reason is a
     * complete message. Anything less is a partial, kept only if this
     * client asked for partials and only if there is enough to act on.
     * That is the same PartialMessagePolicy the AI call path and the
     * operator workspace consult — an answering service cannot sensibly
     * keep partials from a phone call and discard them from a text.
     *
     * @param  array<string, mixed>  $data
     */
    public function takeMessage(array $data): void
    {
        if (! $this->thread || $this->thread->assigned_operator_id !== auth()->id()) {
            Notification::make()
                ->title('Claim this conversation first')
                ->body('Only the operator handling a conversation can take a message from it.')
                ->warning()
                ->send();

            return;
        }

        $policy = app(PartialMessagePolicy::class);

        $captured = [
            'caller_name' => $data['caller_name'] ?? null,
            'caller_phone' => $data['caller_phone'] ?? null,
            'reason' => $data['reason'] ?? null,
        ];

        $context = [
            'team_id' => $this->thread->team_id,
            'created_by_user_id' => auth()->id(),
            'message_thread_id' => $this->thread->id,
            'urgency' => $data['urgency'] ?? Message::URGENCY_NORMAL,
        ];

        if ($policy->isComplete($captured)) {
            Message::create($context + [
                'caller_name' => trim((string) $captured['caller_name']),
                'caller_phone' => $captured['caller_phone'] ? trim((string) $captured['caller_phone']) : null,
                'reason' => trim((string) $captured['reason']),
                'status' => Message::STATUS_NEW,
                'is_partial' => false,
            ]);

            $this->afterMessageTaken('Message saved', 'The client can see it in their portal now.');

            return;
        }

        // No intake goal to consult on this channel — message queues
        // carry an orchestration, not a goal, so the client-level
        // default applies. Same explicit seam as
        // SessionMessageWriter::goalFor(): when threads start carrying
        // the goal that drove them, this is the only line that changes.
        if (! $policy->keepsPartials($this->thread->team, null)) {
            Notification::make()
                ->title('Incomplete message not saved')
                ->body('This client does not keep partial messages. Add a name and a reason, or turn on "Keep partial messages" on the client record.')
                ->warning()
                ->send();

            return;
        }

        if (! $policy->isWorthKeeping($captured)) {
            Notification::make()
                ->title('Not enough to save')
                ->body('A partial message needs a callback number, or a name and something they said.')
                ->warning()
                ->send();

            return;
        }

        // array_merge, not `+`: the operator's urgency selection has to
        // beat the policy's "normal" default, and `+` keeps the
        // left-hand key.
        Message::create(array_merge(
            $policy->attributesFor($captured, PartialMessagePolicy::REASON_OPERATOR_SAVED),
            $context,
        ));

        $this->afterMessageTaken(
            'Partial message saved',
            'Marked incomplete so the client knows what was never given.',
        );
    }

    private function afterMessageTaken(string $title, string $body): void
    {
        ConversationActivity::log($this->thread, 'message_taken', user: auth()->user());
        $this->refreshThread();

        Notification::make()->title($title)->body($body)->success()->send();
    }

    /**
     * Reload the thread and its entries after any state change.
     *
     * Never a redirect: the operator panel hosts a live softphone, and
     * a page reload drops an active call.
     */
    public function refreshThread(): void
    {
        $this->thread = MessageThread::with([
            'team', 'queue', 'assignedOperator', 'endpoint',
            'entries.sentByUser', 'entries.agentPersona',
        ])->find($this->thread->id);
    }

    private function bustBadgeCache(): void
    {
        Cache::forget('operator:'.auth()->id().':message_inbox_badge');
        $this->dispatch('refresh-sidebar');
    }
}
