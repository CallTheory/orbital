<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\ConversationActivity;
use App\Models\EmailThread;
use App\Services\Mail\OutboundForwardService;
use App\Services\Mail\OutboundReplyService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

/**
 * Full-page thread viewer for a single email thread.
 *
 * Navigated to from EmailInbox via SPA link — no full page reload,
 * softphone stays connected. Messages render as collapsible
 * Filament sections (most recent expanded, rest collapsed).
 * Activity history renders as a Filament table via a child
 * Livewire component.
 *
 * Header actions: Reply, Forward, Close/Reopen, Back to Inbox.
 */
class ViewEmailThread extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'email-thread/{threadId}';

    protected string $view = 'filament.operator.pages.view-email-thread';

    public ?EmailThread $thread = null;

    public function mount(int $threadId): void
    {
        $this->thread = EmailThread::with([
            'team', 'emailQueue', 'assignedOperator', 'messages.attachments',
        ])->findOrFail($threadId);

        static::$title = $this->thread->subject_root ?: '(no subject)';
    }

    public function getSubheading(): ?string
    {
        $parts = [];
        $parts[] = 'Tenant: '.$this->thread->team->name;
        if ($this->thread->emailQueue) {
            $parts[] = 'Queue: '.$this->thread->emailQueue->name;
        }
        if ($this->thread->assignedOperator) {
            $parts[] = 'Claimed by: '.$this->thread->assignedOperator->name;
        }
        $operatorTz = auth()->user()?->displayTimezone() ?? config('app.timezone');
        $tenantTz = $this->thread->team?->displayTimezone() ?? config('app.timezone');
        if ($operatorTz !== $tenantTz) {
            $parts[] = 'Tenant tz: '.$tenantTz;
        }

        return implode('  ·  ', $parts);
    }

    /**
     * Messages as collapsible sections — most recent expanded,
     * rest collapsed. Each section heading shows sender + time,
     * body shows the infolist fields.
     */
    public function messagesInfolist(Schema $schema): Schema
    {
        $operatorTz = auth()->user()?->displayTimezone() ?? config('app.timezone');
        $tenantTz = $this->thread->team?->displayTimezone() ?? config('app.timezone');
        $showBothTz = $operatorTz !== $tenantTz;

        $messages = $this->thread->messages->sortByDesc('received_at')->values();
        $sections = [];

        foreach ($messages as $index => $msg) {
            $from = $msg->from_name
                ? "{$msg->from_name} <{$msg->from_address}>"
                : $msg->from_address;

            $time = $msg->received_at
                ? $msg->received_at->timezone($operatorTz)->format('M j, g:i a T')
                : '';

            if ($showBothTz && $msg->received_at) {
                $time .= ' / '.$msg->received_at->timezone($tenantTz)->format('g:i a T');
            }

            $directionLabel = $msg->direction === 'outbound' ? ' · sent' : ' · received';
            $heading = "{$from}{$directionLabel}";
            $description = "{$time} — {$msg->subject}";

            $toList = collect($msg->to_addresses)
                ->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)
                ->filter()
                ->join(', ');

            $attachmentText = $msg->attachments->isNotEmpty()
                ? $msg->attachments->map(fn ($a) => "{$a->filename} (".number_format($a->size_bytes / 1024, 1).' KB)')->join(', ')
                : null;

            $isFirst = $index === 0;

            $sections[] = Section::make("message_{$msg->id}")
                ->heading($heading)
                ->description($description)
                ->icon($msg->direction === 'outbound' ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-envelope')
                ->iconColor($msg->direction === 'outbound' ? 'info' : 'gray')
                ->collapsible()
                ->collapsed(! $isFirst)
                ->compact()
                ->schema([
                    TextEntry::make("to_{$msg->id}")
                        ->label('To')
                        ->state($toList)
                        ->hidden(empty($toList)),
                    TextEntry::make("subject_{$msg->id}")
                        ->label('Subject')
                        ->state($msg->subject ?: '(none)'),
                    TextEntry::make("body_{$msg->id}")
                        ->label('Body')
                        ->state($msg->body_text ?: '(no body)')
                        ->columnSpanFull(),
                    TextEntry::make("attachments_{$msg->id}")
                        ->label('Attachments')
                        ->state($attachmentText)
                        ->hidden($attachmentText === null)
                        ->columnSpanFull(),
                ]);
        }

        return $schema->schema($sections);
    }

    /**
     * Activity table — uses the page's single InteractsWithTable
     * slot. Shown below the message accordion.
     */
    public function table(Table $table): Table
    {
        $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');

        return $table
            ->query(
                ConversationActivity::query()
                    ->where('subject_type', (new EmailThread)->getMorphClass())
                    ->where('subject_id', $this->thread->id)
                    ->with('user')
                    ->latest('created_at'),
            )
            ->heading('Activity')
            ->paginated(false)
            ->striped()
            ->columns([
                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->size(TextSize::ExtraSmall)
                    ->formatStateUsing(fn (string $state, ConversationActivity $record): string => match ($state) {
                        'replied' => 'Replied to '.collect($record->metadata['to'] ?? [])->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)->filter()->join(', '),
                        'forwarded' => 'Forwarded to '.($record->metadata['to'] ?? 'unknown'),
                        default => ucfirst($state),
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->size(TextSize::ExtraSmall)
                    ->dateTime('g:i a')
                    ->timezone($tz)
                    ->tooltip(fn (ConversationActivity $record) => $record->created_at?->timezone($tz)->format('M j, Y g:i:s a T')),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('By')
                    ->size(TextSize::ExtraSmall)
                    ->placeholder('System'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Inbox')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(url('/operator/email-inbox')),
            Action::make('claim')
                ->label('Claim')
                ->icon('heroicon-m-hand-raised')
                ->color('success')
                ->visible(fn () => $this->thread->assigned_operator_id === null)
                ->action(function () {
                    $this->thread->forceFill([
                        'assigned_operator_id' => auth()->id(),
                        'status' => EmailThread::STATUS_IN_PROGRESS,
                    ])->save();
                    ConversationActivity::log($this->thread, 'claimed', user: auth()->user());
                    $this->bustBadgeCache();
                    $this->refreshThread();
                    Notification::make()->title('Thread claimed')->success()->send();
                }),
            Action::make('unclaim')
                ->label('Unclaim')
                ->icon('heroicon-m-hand-raised')
                ->color('gray')
                ->visible(fn () => $this->thread->assigned_operator_id === auth()->id())
                ->requiresConfirmation()
                ->modalDescription('Release this thread back to the unclaimed pool.')
                ->action(function () {
                    $this->thread->forceFill([
                        'assigned_operator_id' => null,
                        'status' => EmailThread::STATUS_NEW,
                    ])->save();
                    ConversationActivity::log($this->thread, 'unclaimed', user: auth()->user());
                    $this->bustBadgeCache();
                    $this->refreshThread();
                    Notification::make()->title('Thread unclaimed')->success()->send();
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
                ->action(function (array $data) {
                    try {
                        $user = auth()->user();
                        $outbound = app(OutboundReplyService::class)->reply(
                            thread: $this->thread,
                            bodyText: $data['body_text'],
                            sentByOperator: $user,
                        );
                        ConversationActivity::log($this->thread, 'replied', [
                            'to' => $outbound->to_addresses,
                            'subject' => $outbound->subject,
                            'message_id' => $outbound->id,
                            'body_preview' => mb_substr($data['body_text'], 0, 200),
                        ], user: $user);
                        $this->bustBadgeCache();
                        $this->refreshThread();
                        Notification::make()->title('Reply sent')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Reply failed')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('forward')
                ->label('Forward')
                ->icon('heroicon-m-arrow-uturn-right')
                ->color('gray')
                ->schema([
                    Forms\Components\TextInput::make('to_address')
                        ->label('Forward to')
                        ->email()
                        ->required()
                        ->placeholder('recipient@example.com'),
                    Forms\Components\Textarea::make('note')
                        ->label('Note')
                        ->rows(6)
                        ->placeholder('Optional note above the forwarded message…'),
                ])
                ->modalWidth('3xl')
                ->action(function (array $data) {
                    try {
                        $user = auth()->user();
                        $outbound = app(OutboundForwardService::class)->forward(
                            thread: $this->thread,
                            toAddress: $data['to_address'],
                            note: $data['note'] ?? '',
                            sentByOperator: $user,
                        );
                        ConversationActivity::log($this->thread, 'forwarded', [
                            'to' => $data['to_address'],
                            'subject' => $outbound->subject,
                            'message_id' => $outbound->id,
                            'note_preview' => mb_substr($data['note'] ?? '', 0, 200),
                        ], user: $user);
                        $this->bustBadgeCache();
                        $this->refreshThread();
                        Notification::make()->title('Message forwarded')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Forward failed')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('close_thread')
                ->label('Close thread')
                ->icon('heroicon-m-check-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Mark this thread as handled. It will drop out of the default inbox view.')
                ->visible(fn () => $this->thread->status !== EmailThread::STATUS_CLOSED)
                ->action(function () {
                    $this->thread->update(['status' => EmailThread::STATUS_CLOSED]);
                    ConversationActivity::log($this->thread, 'closed', user: auth()->user());
                    $this->bustBadgeCache();

                    return redirect(url('/operator/email-inbox'));
                }),
            Action::make('reopen_thread')
                ->label('Reopen thread')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Move this thread back to in-progress so it appears in the active inbox.')
                ->visible(fn () => $this->thread->status === EmailThread::STATUS_CLOSED)
                ->action(function () {
                    $this->thread->update(['status' => EmailThread::STATUS_IN_PROGRESS]);
                    ConversationActivity::log($this->thread, 'reopened', user: auth()->user());
                    $this->bustBadgeCache();
                    $this->refreshThread();
                    Notification::make()->title('Thread reopened')->success()->send();
                }),
        ];
    }

    private function bustBadgeCache(): void
    {
        Cache::forget('operator:'.auth()->id().':email_inbox_badge');
        $this->dispatch('refresh-sidebar');
    }

    private function refreshThread(): void
    {
        $this->thread->refresh();
        $this->thread->load(['team', 'emailQueue', 'assignedOperator', 'messages.attachments']);
    }
}
