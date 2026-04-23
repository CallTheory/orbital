<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\EmailThread;
use Illuminate\Support\Str;

/**
 * Groups incoming email messages into conversation threads.
 *
 * Resolution order:
 *
 *   1. `In-Reply-To` header → look up an existing message by
 *      its Message-ID in the same client, return that message's
 *      thread_id.
 *   2. `References` header → walk the chain of Message-IDs,
 *      same lookup, same client.
 *   3. Sender + subject-root match within a 7-day window
 *      (catches plain replies from mail clients that strip
 *      `In-Reply-To` / `References`, which is depressingly
 *      common).
 *   4. Otherwise, create a new thread keyed off the current
 *      message's subject root.
 *
 * Client-scoped throughout — two clients sending mail about
 * "Order #1234" will never accidentally share a thread because
 * the header / sender / subject lookups all filter by `team_id`.
 */
class ThreadResolver
{
    /** Fuzzy-match window for the subject + sender fallback. */
    private const FALLBACK_DAYS = 7;

    public function resolve(EmailMessage $message, int $teamId): EmailThread
    {
        // 1. In-Reply-To — direct parent
        if ($message->in_reply_to) {
            $parent = EmailMessage::query()
                ->where('team_id', $teamId)
                ->where('message_id', $message->in_reply_to)
                ->whereNotNull('thread_id')
                ->first();
            if ($parent) {
                return $parent->thread;
            }
        }

        // 2. References chain — walk newest → oldest (RFC5322
        // says the last entry is the direct parent, first is the
        // root of the chain). We try each; any hit wins.
        $refs = is_array($message->references) ? $message->references : [];
        if (! empty($refs)) {
            $hit = EmailMessage::query()
                ->where('team_id', $teamId)
                ->whereIn('message_id', $refs)
                ->whereNotNull('thread_id')
                ->orderByDesc('received_at')
                ->first();
            if ($hit) {
                return $hit->thread;
            }
        }

        // 3. Sender + subject-root fallback. Some mail clients
        // (notably older Outlook, Thunderbird without "threaded")
        // drop the References chain on reply. Fall back to "same
        // from + same stripped subject within 7 days".
        $root = $this->subjectRoot($message->subject);
        if ($root !== null && $message->from_address) {
            $fallback = EmailThread::query()
                ->where('team_id', $teamId)
                ->where('subject_root', $root)
                ->whereHas('messages', fn ($q) => $q->where('from_address', $message->from_address))
                ->where('last_message_at', '>=', now()->subDays(self::FALLBACK_DAYS))
                ->orderByDesc('last_message_at')
                ->first();
            if ($fallback) {
                return $fallback;
            }
        }

        // 4. New thread
        $thread = EmailThread::create([
            'team_id' => $teamId,
            'subject_root' => $root,
            'participants' => $this->initialParticipants($message),
            'status' => EmailThread::STATUS_NEW,
            'last_message_at' => $message->received_at,
        ]);

        return $thread;
    }

    /**
     * Strip `Re:` / `Fwd:` / `RE:` / `FW:` prefixes from a
     * subject so replies thread under the same root.
     */
    private function subjectRoot(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }
        $cleaned = preg_replace('/^\s*((re|fwd?|fw|sv|antw)\s*:\s*)+/i', '', $subject) ?? $subject;
        $cleaned = trim($cleaned);
        return $cleaned === '' ? null : Str::limit($cleaned, 1000, '');
    }

    /**
     * Build the initial participants list for a new thread from
     * the message's from/to/cc fields. Unique, keyed by lowercase
     * address so downstream code can do set operations.
     *
     * @return array<int, string>
     */
    private function initialParticipants(EmailMessage $message): array
    {
        $all = [];
        if ($message->from_address) {
            $all[] = $message->from_address;
        }
        foreach (($message->to_addresses ?? []) as $addr) {
            $all[] = is_array($addr) ? ($addr['address'] ?? null) : $addr;
        }
        foreach (($message->cc_addresses ?? []) as $addr) {
            $all[] = is_array($addr) ? ($addr['address'] ?? null) : $addr;
        }
        return array_values(array_unique(array_filter(array_map(
            fn ($a) => $a ? strtolower((string) $a) : null,
            $all,
        ))));
    }
}
