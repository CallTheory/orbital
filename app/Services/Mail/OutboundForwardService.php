<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\ThreadForward;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Forwards the latest message in an EmailThread to an arbitrary
 * address — typically a client contact, supervisor, or external
 * party. The operator can prepend their own note above the
 * forwarded body.
 *
 * Like OutboundReplyService, the outbound message is persisted
 * on the thread so the conversation history stays complete.
 */
class OutboundForwardService
{
    public function forward(
        EmailThread $thread,
        string $toAddress,
        string $note,
        ?User $sentByOperator = null,
    ): EmailMessage {
        $latest = $thread->messages()
            ->latest('received_at')
            ->first();

        if (! $latest) {
            throw new \RuntimeException("Thread {$thread->id} has no messages to forward");
        }

        $baseSubject = $latest->subject ?? $thread->subject_root ?? '(no subject)';
        $cleaned = preg_replace('/^\s*((re|fwd?|fw)\s*:\s*)+/i', '', $baseSubject) ?? $baseSubject;
        $fwdSubject = 'Fwd: '.trim($cleaned);

        $fromAddress = $this->buildTenantFromAddress($thread);
        $fromName = config('mail.from.name');

        $outboundMessageId = sprintf(
            '%s.%s@%s',
            (string) Str::ulid(),
            $thread->id,
            config('services.inbound_mail.domain', 'inbound.orbital.test'),
        );

        // Build the forwarded body with the operator's note on top.
        $divider = "\n\n---------- Forwarded message ----------\n";
        $forwardMeta = sprintf(
            "From: %s\nDate: %s\nSubject: %s\nTo: %s\n\n",
            $latest->from_name ? "{$latest->from_name} <{$latest->from_address}>" : $latest->from_address,
            $latest->received_at?->format('r') ?? 'unknown',
            $latest->subject ?? '(no subject)',
            collect($latest->to_addresses)->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)->filter()->join(', '),
        );

        $bodyText = trim($note).$divider.$forwardMeta.($latest->body_text ?? '');
        $bodyHtml = null;

        if ($latest->body_html) {
            $noteHtml = nl2br(e(trim($note)));
            $bodyHtml = $noteHtml
                .'<br><br><hr style="border:none;border-top:1px solid #ccc;">'
                .'<p style="font-size:12px;color:#888;">'
                .e('From: '.($latest->from_name ? "{$latest->from_name} <{$latest->from_address}>" : $latest->from_address)).'<br>'
                .e('Date: '.($latest->received_at?->format('r') ?? 'unknown')).'<br>'
                .e('Subject: '.($latest->subject ?? '(no subject)'))
                .'</p>'
                .$latest->body_html;
        }

        $mailable = new ThreadForward(
            toAddress: $toAddress,
            fromAddress: $fromAddress,
            fromName: is_string($fromName) ? $fromName : null,
            subject: $fwdSubject,
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
        );

        $mailable->withSymfonyMessage(function ($message) use ($outboundMessageId) {
            $message->getHeaders()->addIdHeader('Message-ID', $outboundMessageId);
        });

        Mail::to($toAddress)->send($mailable);

        $outbound = EmailMessage::create([
            'team_id' => $thread->team_id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'raw_storage_path' => 'outbound/synthetic',
            'message_id' => $outboundMessageId,
            'from_address' => $fromAddress,
            'from_name' => is_string($fromName) ? $fromName : null,
            'to_addresses' => [['address' => $toAddress, 'name' => null]],
            'cc_addresses' => [],
            'subject' => $fwdSubject,
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'received_at' => now(),
            'processed_at' => now(),
            'routing_status' => 'routed',
            'metadata' => [
                'sent_by_operator_id' => $sentByOperator?->id,
                'forward' => true,
            ],
        ]);

        $thread->forceFill([
            'last_message_at' => $outbound->received_at,
        ])->save();

        Log::info('outbound email forwarded', [
            'thread_id' => $thread->id,
            'message_id' => $outboundMessageId,
            'to' => $toAddress,
            'from' => $fromAddress,
        ]);

        return $outbound;
    }

    private function buildTenantFromAddress(EmailThread $thread): string
    {
        $team = $thread->team;
        $domain = (string) config('services.inbound_mail.domain', 'inbound.orbital.test');
        if ($team && $team->account_number) {
            return "{$team->account_number}@{$domain}";
        }

        return (string) config('mail.from.address');
    }
}
