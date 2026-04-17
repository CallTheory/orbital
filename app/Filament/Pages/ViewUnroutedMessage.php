<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Mail\ThreadForward;
use App\Models\EmailMessage;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Detail view for a single failed email message.
 *
 * Shows parsed metadata (whatever was extracted before the
 * failure) plus the raw MIME source for diagnosis. Header
 * actions: Forward, Discard, Back.
 */
class ViewUnroutedMessage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'mail/failed/{messageId}';

    protected string $view = 'filament.pages.view-unrouted-message';

    public ?EmailMessage $message = null;

    public ?string $rawMime = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(int $messageId): void
    {
        $this->message = EmailMessage::query()
            ->where('routing_status', 'failed')
            ->findOrFail($messageId);

        static::$title = $this->message->subject ?: '(no subject)';

        try {
            $this->rawMime = Storage::disk('s3')->get($this->message->raw_storage_path);
        } catch (\Throwable) {
            $this->rawMime = null;
        }
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function messageInfolist(Schema $schema): Schema
    {
        $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');
        $msg = $this->message;

        $from = $msg->from_name
            ? "{$msg->from_name} <{$msg->from_address}>"
            : ($msg->from_address ?? 'unknown');

        $to = collect($msg->to_addresses)
            ->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)
            ->filter()
            ->join(', ');

        $deliveredTo = implode(', ', $msg->metadata['envelope_to'] ?? []);

        return $schema->schema([
            Section::make('message_details')
                ->heading('Parsed Headers')
                ->description('Whatever was extracted before the failure — may be incomplete.')
                ->compact()
                ->schema([
                    TextEntry::make('from')
                        ->label('From')
                        ->state($from),
                    TextEntry::make('received_at_display')
                        ->label('Received')
                        ->state($msg->received_at?->timezone($tz)->format('M j, Y g:i:s a T')),
                    TextEntry::make('to')
                        ->label('To')
                        ->state($to ?: '—'),
                    TextEntry::make('delivered_to')
                        ->label('Delivered to')
                        ->state($deliveredTo ?: '—'),
                    TextEntry::make('subject_display')
                        ->label('Subject')
                        ->state($msg->subject ?: '(none)')
                        ->columnSpanFull(),
                    TextEntry::make('error')
                        ->label('Error')
                        ->state($msg->metadata['last_error'] ?? '(no error recorded)')
                        ->badge()
                        ->color('danger')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('raw_mime')
                ->heading('Raw Message (RFC822)')
                ->compact()
                ->schema([
                    TextEntry::make('raw')
                        ->hiddenLabel()
                        ->state($this->rawMime ?? '(raw MIME blob not available)')
                        ->formatStateUsing(fn (string $state) => '<pre style="white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere; margin: 0;">'.e($state).'</pre>')
                        ->html()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(url('/admin/mail/failed')),

            Action::make('forward')
                ->label('Forward')
                ->icon('heroicon-m-arrow-uturn-right')
                ->color('gray')
                ->schema([
                    Forms\Components\TextInput::make('to_address')
                        ->label('Forward to')
                        ->email()
                        ->required()
                        ->placeholder('admin@example.com'),
                    Forms\Components\Textarea::make('note')
                        ->label('Note')
                        ->rows(4)
                        ->placeholder('Optional note above the forwarded message…'),
                ])
                ->modalWidth('xl')
                ->action(function (array $data) {
                    try {
                        $msg = $this->message;
                        $error = $msg->metadata['last_error'] ?? 'unknown error';
                        $from = $msg->from_name ? "{$msg->from_name} <{$msg->from_address}>" : ($msg->from_address ?? 'unknown');
                        $deliveredTo = implode(', ', $msg->metadata['envelope_to'] ?? []);

                        $bodyText = trim($data['note'] ?? '')."\n\n"
                            ."--- Failed Email Report ---\n"
                            ."Error: {$error}\n"
                            ."From: {$from}\n"
                            ."Delivered to: {$deliveredTo}\n"
                            .'Subject: '.($msg->subject ?? '(none)')."\n"
                            .'Received: '.($msg->received_at?->format('r') ?? 'unknown')."\n"
                            ."\n--- Raw Message (RFC822) ---\n\n"
                            .($this->rawMime ?? '(raw MIME blob not available)');

                        $subject = 'Fwd: [FAILED] '.($msg->subject ?? '(no subject)');

                        $mailable = new ThreadForward(
                            toAddress: $data['to_address'],
                            fromAddress: (string) config('mail.from.address'),
                            fromName: (string) config('mail.from.name'),
                            subject: $subject,
                            bodyText: $bodyText,
                            bodyHtml: null,
                        );

                        Mail::to($data['to_address'])->send($mailable);
                        Notification::make()->title('Forwarded with error report')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Forward failed')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('discard')
                ->label('Discard')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Delete this message and its raw MIME blob permanently.')
                ->action(function () {
                    Storage::disk('s3')->delete($this->message->raw_storage_path);
                    $this->message->delete();
                    $this->dispatch('refresh-sidebar');
                    Notification::make()->title('Discarded')->success()->send();

                    return redirect(url('/admin/mail/failed'));
                }),
        ];
    }
}
