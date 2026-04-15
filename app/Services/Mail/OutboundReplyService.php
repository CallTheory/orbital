<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\ThreadReply;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends a threaded reply from an operator or AI persona to the
 * latest inbound message in an EmailThread.
 *
 * Responsibilities:
 *   1. Figure out who the reply goes to (the latest inbound
 *      message's `from_address`)
 *   2. Build In-Reply-To + References headers from the inbound
 *      message so the reply threads correctly in the recipient's
 *      mail client
 *   3. Compute a reply-from address for the tenant — uses the
 *      `{account_number}@{INBOUND_MAIL_DOMAIN}` pattern so
 *      responses from the other side come back through Haraka
 *      and land on the same thread
 *   4. Send via Laravel Mailer (dev → Mailpit, prod → whatever
 *      SMTP relay is configured — see TODO.md for the Postal
 *      item)
 *   5. Persist an outbound `EmailMessage` row on the thread so
 *      operators see both sides of the conversation
 *   6. Bump the thread's `last_message_at` and transition its
 *      status to `awaiting_reply`
 *
 * Called from:
 *   - Operator EmailInbox page reply action
 *   - ProcessEmailWithAgentJob when an AI persona replies
 */
class OutboundReplyService
{
    public function reply(
        EmailThread $thread,
        string $bodyText,
        ?string $bodyHtml = null,
        ?User $sentByOperator = null,
    ): EmailMessage {
        // Latest inbound message is the reply target. Without an
        // inbound parent we'd be starting a new thread, which
        // isn't something this service supports.
        $inbound = $thread->messages()
            ->where('direction', 'inbound')
            ->latest('received_at')
            ->first();

        if (! $inbound) {
            throw new \RuntimeException("Thread {$thread->id} has no inbound message to reply to");
        }

        $toAddress = $inbound->from_address;
        if (! $toAddress) {
            throw new \RuntimeException("Inbound message {$inbound->id} has no from_address");
        }

        // Reply subject — strip any existing Re: prefix and
        // re-add exactly one so replies don't accumulate
        // "Re: Re: Re: ...".
        $baseSubject = $inbound->subject ?? '(no subject)';
        $cleaned = preg_replace('/^\s*((re|fwd?|fw)\s*:\s*)+/i', '', $baseSubject) ?? $baseSubject;
        $replySubject = 'Re: '.trim($cleaned);

        // References chain: the existing chain + the message we're
        // replying to's Message-ID. Order matters for RFC5322
        // (oldest first), though most clients tolerate either.
        $references = is_array($inbound->references) ? $inbound->references : [];
        if ($inbound->message_id) {
            $references[] = $inbound->message_id;
        }
        $references = array_values(array_unique($references));

        // From address — a tenant-scoped inbound address so
        // recipient replies come back to this same thread via
        // Haraka's account_number lookup. Falls back to
        // MAIL_FROM_ADDRESS when the tenant has no account number.
        $fromAddress = $this->buildTenantFromAddress($thread);
        $fromName = config('mail.from.name');

        // Generate a Message-ID for the outbound message so
        // any future inbound reply can chain back via
        // In-Reply-To lookup in ThreadResolver.
        $outboundMessageId = sprintf(
            '%s.%s@%s',
            (string) Str::ulid(),
            $thread->id,
            config('services.inbound_mail.domain', 'inbound.orbital.test'),
        );

        // Build and send. Mail::send would also work but the
        // Mailable lets us express headers cleanly.
        $mailable = new ThreadReply(
            toAddress: $toAddress,
            fromAddress: $fromAddress,
            fromName: is_string($fromName) ? $fromName : null,
            replySubject: $replySubject,
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            inReplyTo: $inbound->message_id,
            references: $references,
        );

        // Stamp our own Message-ID on the outbound message too
        // so receivers use it as the In-Reply-To target of any
        // follow-up. Laravel's Mailables don't expose Message-ID
        // directly, so we set it via a raw header on the
        // Symfony\Mime\Email at build time.
        $mailable->withSymfonyMessage(function ($message) use ($outboundMessageId) {
            $message->getHeaders()->addIdHeader('Message-ID', $outboundMessageId);
        });

        Mail::to($toAddress)->send($mailable);

        // Persist the outbound message on the thread so both
        // halves of the conversation live together.
        $outbound = EmailMessage::create([
            'team_id' => $thread->team_id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            // No raw RFC822 blob for outbound — we built it,
            // we know what's in it. raw_storage_path is
            // required by the schema so stash a sentinel.
            'raw_storage_path' => 'outbound/synthetic',
            'message_id' => $outboundMessageId,
            'in_reply_to' => $inbound->message_id,
            'references' => $references,
            'from_address' => $fromAddress,
            'from_name' => is_string($fromName) ? $fromName : null,
            'to_addresses' => [['address' => $toAddress, 'name' => null]],
            'cc_addresses' => [],
            'subject' => $replySubject,
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'received_at' => now(),
            'processed_at' => now(),
            'routing_status' => 'routed',
            'metadata' => [
                'sent_by_operator_id' => $sentByOperator?->id,
                'sent_by_agent_persona_id' => $thread->assigned_agent_persona_id,
            ],
        ]);

        // Bump thread state.
        $thread->forceFill([
            'last_message_at' => $outbound->received_at,
            'status' => EmailThread::STATUS_AWAITING_REPLY,
        ])->save();

        Log::info('outbound email reply sent', [
            'thread_id' => $thread->id,
            'message_id' => $outboundMessageId,
            'to' => $toAddress,
            'from' => $fromAddress,
            'in_reply_to' => $inbound->message_id,
        ]);

        return $outbound;
    }

    /**
     * Build a tenant-scoped from address of the form
     * `{account_number}@{INBOUND_MAIL_DOMAIN}` so reply-backs
     * come through Haraka and land on the same thread. Falls
     * back to the global MAIL_FROM_ADDRESS if the tenant has
     * no account_number (shouldn't happen in practice — every
     * tenant gets one at create time).
     */
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
