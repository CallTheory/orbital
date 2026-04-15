<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Mail\InboundRouter;
use App\Services\Mail\ThreadResolver;
use BeyondCode\Mailbox\InboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes a stub EmailMessage row written by InboundMailController.
 *
 * Phase 2 flow (current):
 *   1. Load stub, pull raw RFC822 from MinIO
 *   2. Parse MIME via InboundEmail::fromMessage
 *   3. Populate header + body columns
 *   4. Hand to InboundRouter → tenant + destination lookup
 *   5. Hand to ThreadResolver → group into EmailThread
 *   6. Persist attachments to MinIO + index rows
 *   7. Stamp team_id, thread_id, routing_status (routed|unrouted),
 *      routed_destination in metadata, processed_at
 *
 * Runs on the dedicated `inbound-mail` Horizon queue so bursty
 * mail doesn't starve telephony workers. Retries 3x on transient
 * errors (DB, MinIO, parser hiccups) with exponential backoff.
 */
class ProcessInboundEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> Backoff in seconds: 5s, 30s, 2min. */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public int $emailMessageId,
    ) {}

    public function handle(InboundRouter $router, ThreadResolver $resolver): void
    {
        $message = EmailMessage::find($this->emailMessageId);
        if (! $message) {
            Log::warning('inbound mail stub missing by the time job ran', [
                'id' => $this->emailMessageId,
            ]);
            return;
        }

        try {
            $raw = Storage::disk('s3')->get($message->raw_storage_path);
            if ($raw === null || $raw === '') {
                throw new \RuntimeException("raw MIME blob missing at {$message->raw_storage_path}");
            }

            $parsed = InboundEmail::fromMessage($raw);

            // ── 1. Populate header + body from the MIME parse.
            $message->forceFill([
                'message_id' => $this->stripBrackets($parsed->headerValue('Message-ID')),
                'in_reply_to' => $this->stripBrackets($parsed->headerValue('In-Reply-To')),
                'references' => $this->parseReferences($parsed->headerValue('References')),
                'from_address' => $parsed->from(),
                'from_name' => $parsed->fromName() ?: null,
                'to_addresses' => $this->normalizeAddressList($parsed->to()),
                'cc_addresses' => $this->normalizeAddressList($parsed->cc()),
                'subject' => $parsed->subject(),
                'body_text' => $parsed->text(),
                'body_html' => $parsed->html(),
            ])->save();

            // ── 2. Route → tenant + destination.
            $routed = $router->route($message);

            // ── 3. Resolve or create a thread (only if routed
            //       to a real tenant; unrouted messages stay
            //       orphaned for admin review).
            $thread = null;
            if ($routed['team_id'] !== null) {
                $thread = DB::transaction(function () use ($resolver, $message, $routed) {
                    // Must set team_id BEFORE resolving the
                    // thread so the resolver's tenant-scoped
                    // lookups find the right scope.
                    $message->team_id = $routed['team_id'];
                    return $resolver->resolve($message, $routed['team_id']);
                });

                // Stamp queue destination on the thread so the
                // operator inbox can filter by queue. Only on
                // first arrival — later messages on an existing
                // thread inherit whatever queue the thread is
                // already in, so human re-assignments stick.
                if (
                    $thread->email_queue_id === null
                    && $routed['destination_type'] === \App\Models\EmailRoutingRule::DESTINATION_QUEUE
                    && $routed['destination_id'] !== null
                ) {
                    $thread->email_queue_id = $routed['destination_id'];
                    $thread->save();
                }

                // Same idea for operator / agent_persona direct
                // assignment — stamp only if the thread isn't
                // already claimed.
                if (
                    $thread->assigned_operator_id === null
                    && $routed['destination_type'] === \App\Models\EmailRoutingRule::DESTINATION_OPERATOR
                    && $routed['destination_id'] !== null
                ) {
                    $thread->assigned_operator_id = $routed['destination_id'];
                    $thread->save();
                }
                if (
                    $thread->assigned_agent_persona_id === null
                    && $routed['destination_type'] === \App\Models\EmailRoutingRule::DESTINATION_AGENT_PERSONA
                    && $routed['destination_id'] !== null
                ) {
                    $thread->assigned_agent_persona_id = $routed['destination_id'];
                    $thread->save();
                }
            }

            // ── 4. Persist attachments (all messages, routed
            //       or not, so unrouted mail still has its
            //       attachments captured for review).
            $this->storeAttachments($parsed, $message);

            // ── 5. Final state stamp.
            $message->forceFill([
                'team_id' => $routed['team_id'],
                'thread_id' => $thread?->id,
                'routing_status' => $routed['status'],
                'processed_at' => now(),
                'metadata' => array_merge($message->metadata ?? [], [
                    'routed_destination' => [
                        'type' => $routed['destination_type'],
                        'id' => $routed['destination_id'],
                        'function' => $routed['function'],
                        'matched_rule_id' => $routed['matched_rule_id'],
                    ],
                ]),
            ])->save();

            // Bump the thread's last_message_at + append the
            // sender to the rolling participants set.
            if ($thread) {
                $participants = is_array($thread->participants) ? $thread->participants : [];
                if ($message->from_address) {
                    $participants[] = strtolower($message->from_address);
                }
                $thread->forceFill([
                    'last_message_at' => $message->received_at,
                    'participants' => array_values(array_unique($participants)),
                ])->save();

                // If the thread has an assigned AI persona (either
                // stamped by the router just now or set on a
                // previous message in this thread), hand the
                // message off to the AI reply job on the same
                // queue. The job compiles the persona, calls the
                // LLM, and sends via OutboundReplyService.
                if ($thread->assigned_agent_persona_id !== null && $message->direction === 'inbound') {
                    ProcessEmailWithAgentJob::dispatch($thread->id)->onQueue('inbound-mail');
                }
            }

            Log::info('inbound mail processed', [
                'id' => $message->id,
                'team_id' => $routed['team_id'],
                'thread_id' => $thread?->id,
                'status' => $routed['status'],
                'destination' => $routed['destination_type'],
                'function' => $routed['function'],
            ]);
        } catch (Throwable $e) {
            $message->forceFill([
                'routing_status' => 'failed',
                'processed_at' => now(),
                'metadata' => array_merge($message->metadata ?? [], [
                    'last_error' => $e->getMessage(),
                    'last_error_at' => now()->toIso8601String(),
                ]),
            ])->save();

            Log::error('inbound mail processing failed', [
                'id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Walk the parsed message's attachments, upload each to
     * MinIO under a per-message prefix, and write index rows
     * to `email_attachments`. Safe to call even when there are
     * no attachments — it just no-ops.
     */
    private function storeAttachments(InboundEmail $parsed, EmailMessage $message): void
    {
        $atts = $parsed->attachments();
        if (empty($atts)) {
            return;
        }

        foreach ($atts as $index => $att) {
            // zbateson/mail-mime-parser AttachmentPart exposes
            // `getFilename()`, `getContentType()`, `getContent()`,
            // `getContentDisposition()`, `getContentId()`.
            $filename = method_exists($att, 'getFilename') ? $att->getFilename() : null;
            $contentType = method_exists($att, 'getContentType') ? $att->getContentType() : null;
            $content = method_exists($att, 'getContent') ? $att->getContent() : null;
            $disposition = method_exists($att, 'getContentDisposition') ? $att->getContentDisposition() : null;
            $contentId = method_exists($att, 'getContentId') ? $att->getContentId() : null;

            if ($content === null) {
                continue;
            }

            $safeName = $filename ?: "attachment-{$index}";
            $path = sprintf(
                'mail-attachments/%d/%s-%s',
                $message->id,
                (string) Str::ulid(),
                Str::slug(pathinfo($safeName, PATHINFO_FILENAME)).'.'.(pathinfo($safeName, PATHINFO_EXTENSION) ?: 'bin'),
            );

            Storage::disk('s3')->put($path, $content);

            EmailAttachment::create([
                'email_message_id' => $message->id,
                'filename' => $safeName,
                'content_type' => $contentType,
                'size_bytes' => strlen($content),
                'storage_path' => $path,
                'inline' => strcasecmp((string) $disposition, 'inline') === 0,
                'content_id' => $contentId ? $this->stripBrackets($contentId) : null,
            ]);
        }
    }

    /**
     * Split the `References` header into an array of Message-IDs.
     * The header is whitespace-separated `<id1> <id2> <id3>` —
     * strip the angle brackets so callers can match on bare IDs.
     *
     * @return array<int, string>|null
     */
    private function parseReferences(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        return array_values(array_filter(array_map(
            fn ($p) => $this->stripBrackets($p),
            $parts,
        )));
    }

    /**
     * Turn the `InboundEmail::to()` / `::cc()` array of
     * `AddressPart` objects into a plain array of strings suitable
     * for storing in a JSON column.
     *
     * @param  iterable  $addresses
     * @return array<int, array{address: string, name: string|null}>
     */
    private function normalizeAddressList(iterable $addresses): array
    {
        $out = [];
        foreach ($addresses as $a) {
            if (is_string($a)) {
                $out[] = ['address' => $a, 'name' => null];
                continue;
            }
            if (is_object($a)) {
                $address = method_exists($a, 'getValue') ? (string) $a->getValue() : null;
                $name = method_exists($a, 'getName') ? ($a->getName() ?: null) : null;
                if ($address) {
                    $out[] = ['address' => $address, 'name' => $name];
                }
            }
        }
        return $out;
    }

    /**
     * Strip angle brackets off a Message-ID / In-Reply-To header
     * so `<foo@bar>` → `foo@bar`. Returns null unchanged.
     */
    private function stripBrackets(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        return trim($raw, " \t\r\n<>");
    }
}
