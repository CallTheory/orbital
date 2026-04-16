<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\ConversationActivity;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Mail\OutboundForwardService;
use App\Services\Mail\OutboundReplyService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Full-page thread viewer for a single email thread.
 *
 * Navigated to from EmailInbox via SPA link — no full page reload,
 * softphone stays connected. Shows messages as a Filament table
 * and activity history below it.
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
        $this->thread = EmailThread::with(['team', 'emailQueue', 'assignedOperator'])
            ->findOrFail($threadId);

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

    public function table(Table $table): Table
    {
        $operatorTz = auth()->user()?->displayTimezone() ?? config('app.timezone');
        $tenantTz = $this->thread->team?->displayTimezone() ?? config('app.timezone');
        $showBothTz = $operatorTz !== $tenantTz;

        return $table
            ->query(
                EmailMessage::query()
                    ->where('thread_id', $this->thread->id)
                    ->with('attachments')
                    ->orderBy('received_at'),
            )
            ->heading('Messages')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('from_address')
                    ->label('From')
                    ->formatStateUsing(fn (EmailMessage $record) => $record->from_name
                        ? "{$record->from_name} <{$record->from_address}>"
                        : $record->from_address)
                    ->badge()
                    ->color(fn (EmailMessage $record) => $record->direction === 'outbound' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Time')
                    ->formatStateUsing(function ($state) use ($operatorTz, $tenantTz, $showBothTz) {
                        $dt = Carbon::parse($state);
                        $formatted = $dt->timezone($operatorTz)->format('M j, g:i a T');
                        if ($showBothTz) {
                            $formatted .= ' / '.$dt->timezone($tenantTz)->format('g:i a T');
                        }

                        return $formatted;
                    }),
                Tables\Columns\TextColumn::make('subject')
                    ->placeholder('(none)'),
                Tables\Columns\TextColumn::make('to_list')
                    ->label('To')
                    ->getStateUsing(fn (EmailMessage $record) => collect($record->to_addresses)
                        ->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)
                        ->filter()
                        ->join(', '))
                    ->limit(50),
                Tables\Columns\TextColumn::make('body_text')
                    ->label('Body')
                    ->limit(120)
                    ->placeholder('(no body)')
                    ->wrap()
                    ->tooltip(fn (EmailMessage $record) => $record->body_text ? mb_substr($record->body_text, 0, 500) : null),
            ])
            ->actions([
                Action::make('view_body')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->modalHeading(fn (EmailMessage $record) => $record->subject ?: '(no subject)')
                    ->modalContent(fn (EmailMessage $record) => str($record->body_text ?: '(no body)')->toHtmlString())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('3xl'),
            ]);
    }

    /**
     * Activity entries for the blade view to render via a simple
     * Filament table-like display. Returns the raw collection so
     * the view can iterate.
     */
    public function getActivities(): Collection
    {
        return ConversationActivity::query()
            ->where('subject_type', (new EmailThread)->getMorphClass())
            ->where('subject_id', $this->thread->id)
            ->with('user')
            ->orderBy('created_at')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Inbox')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(url('/operator/email-inbox')),
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
                        $this->thread->refresh();
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
                        $this->thread->refresh();
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
                    $this->thread->refresh();
                    Notification::make()->title('Thread reopened')->success()->send();
                }),
        ];
    }

    private function bustBadgeCache(): void
    {
        Cache::forget('operator:'.auth()->id().':email_inbox_badge');
        $this->dispatch('refresh-sidebar');
    }
}
