<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AgentPersona;
use App\Models\MessageThread;
use App\Services\Flows\AgentFlowCompiler;
use App\Services\Messaging\OptOutRegistry;
use App\Services\Messaging\OutboundMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * AI handoff for inbound message threads.
 *
 * The messaging sibling of ProcessEmailWithAgentJob, reusing the same
 * AgentFlowCompiler so a client's orchestration drives voice, email,
 * and SMS from one definition — which is the point of the shared flow
 * model.
 *
 * What's different is the medium, and it's not cosmetic. SMS is
 * ~160 characters per segment, each segment costs money, and a
 * multi-paragraph reply arrives on a handset as an unreadable wall
 * split across five notifications. The prompt says so explicitly and
 * the reply is truncated as a backstop, because a model asked to be
 * brief is not the same as a model that will be.
 *
 * Runs on the `inbound-messages` queue alongside the rest of the
 * channel.
 */
class ProcessMessageWithAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /**
     * Hard ceiling on a generated reply. Three SMS segments — beyond
     * that the customer is reading a wall of text on a phone and the
     * client is paying per segment for the privilege.
     */
    private const MAX_REPLY_CHARS = 480;

    public function __construct(
        public int $threadId,
    ) {
        $this->onQueue('inbound-messages');
    }

    public function handle(
        AgentFlowCompiler $compiler,
        OutboundMessageService $outbound,
        OptOutRegistry $optOuts,
    ): void {
        $thread = MessageThread::withoutGlobalScopes()
            ->with(['entries', 'team', 'queue', 'endpoint'])
            ->find($this->threadId);

        if (! $thread) {
            Log::warning('agent message job: thread missing', ['thread_id' => $this->threadId]);

            return;
        }

        // Checked here as well as in OutboundMessageService, which is
        // where the send is actually blocked. Duplicated on purpose: the
        // expensive half of this job is the LLM call, and generating a
        // reply we are forbidden to send costs money and produces a
        // failed entry the operator then has to make sense of.
        if ($optOuts->isThreadSuppressed($thread)) {
            Log::info('agent message job: recipient has opted out, standing down', [
                'thread_id' => $this->threadId,
            ]);

            return;
        }

        $persona = $this->resolvePersona($thread);

        if (! $persona) {
            // No persona configured is the normal state for a
            // human-worked queue, not an error worth alarming on.
            Log::info('agent message job: no persona for thread', ['thread_id' => $this->threadId]);

            return;
        }

        // An operator has picked this conversation up. The AI does not
        // talk over a human who is already handling a customer —
        // whichever of them the customer is talking to, they should not
        // suddenly be talking to both.
        if ($thread->assigned_operator_id !== null) {
            Log::info('agent message job: thread claimed by an operator, standing down', [
                'thread_id' => $this->threadId,
                'operator_id' => $thread->assigned_operator_id,
            ]);

            return;
        }

        $compiled = $compiler->compile($persona);

        $history = $this->projectHistory($thread);

        if ($history === []) {
            Log::warning('agent message job: empty history', ['thread_id' => $this->threadId]);

            return;
        }

        // Don't reply to our own last word. If the most recent entry is
        // outbound, we already answered and a retry got this far — a
        // second reply would look to the customer like the agent talking
        // to itself.
        if (end($history)['role'] === 'assistant') {
            return;
        }

        try {
            $reply = $this->callAnthropic(
                $this->buildSystemPrompt($compiled->llmInstructions ?? '', $persona, $thread),
                $history,
            );
        } catch (Throwable $e) {
            Log::error('agent message job: LLM call failed', [
                'thread_id' => $this->threadId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $outbound->replyAsAgent($thread, $persona, $this->trim($reply));

        Log::info('agent message reply sent', [
            'thread_id' => $this->threadId,
            'persona_id' => $persona->id,
        ]);
    }

    /**
     * Which persona answers: the one explicitly assigned to the thread,
     * otherwise the queue's overflow persona.
     */
    private function resolvePersona(MessageThread $thread): ?AgentPersona
    {
        $personaId = $thread->assigned_agent_persona_id
            ?? $thread->queue?->overflow_agent_persona_id;

        if (! $personaId) {
            return null;
        }

        return AgentPersona::withoutGlobalScope('team')->find($personaId);
    }

    /**
     * Project the conversation into Anthropic's user/assistant turns.
     *
     * Inbound (the customer) → user; outbound (us, human or AI) →
     * assistant. Operator replies are included deliberately: when a
     * human hands a conversation back to the AI, the AI needs to know
     * what the human already promised the customer.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function projectHistory(MessageThread $thread): array
    {
        $history = [];

        foreach ($thread->entries as $entry) {
            $text = trim((string) $entry->body);

            if ($text === '') {
                continue;
            }

            // A reply the carrier told us never landed is not part of
            // the conversation the customer has had. Including it would
            // have the agent building on something the customer never
            // read.
            if ($entry->failedToDeliver()) {
                continue;
            }

            $role = $entry->isInbound() ? 'user' : 'assistant';

            // Anthropic requires alternating turns; consecutive same-role
            // messages get merged rather than dropped, because two texts
            // in a row from one side is completely normal in SMS.
            $last = array_key_last($history);

            if ($last !== null && $history[$last]['role'] === $role) {
                $history[$last]['content'] .= "\n".$text;

                continue;
            }

            $history[] = ['role' => $role, 'content' => $text];
        }

        // The API requires the first turn to be `user`. A conversation
        // that opens with an outbound message — an operator texting a
        // customer first — would otherwise be rejected outright. Drop
        // leading assistant turns rather than fail the whole reply.
        while ($history !== [] && $history[0]['role'] === 'assistant') {
            array_shift($history);
        }

        return array_values($history);
    }

    private function buildSystemPrompt(string $compiledInstructions, AgentPersona $persona, MessageThread $thread): string
    {
        $clientName = $thread->team?->name ?? 'the client';

        $preamble = <<<TEXT
You are answering a text message conversation on behalf of {$clientName}.

Medium-specific rules — these override any conflicting guidance below:
- Text messages are SHORT. Two sentences is normal; four is long.
- Never exceed 480 characters. The message is split and billed per segment.
- Plain text only. No markdown, no bullet lists, no headings, no emoji.
- No stage directions or bracketed annotations of any kind.
- No greeting or sign-off on every message — this is a conversation, not a letter.
- Ask one question at a time. People answer the last question they were asked.
- If you cannot help, say so plainly and say a person will follow up.

Persona: {$persona->name}
TEXT;

        if ($compiledInstructions !== '') {
            return $preamble."\n\nBehavior guidance:\n".$compiledInstructions;
        }

        return $preamble;
    }

    /**
     * Backstop for a model that ignored the length instruction.
     *
     * Cuts at a sentence boundary when one is near the limit, because a
     * reply chopped mid-word reads as a system fault to the customer,
     * where a slightly short one just reads as terse.
     */
    private function trim(string $reply): string
    {
        $reply = trim($reply);

        if (mb_strlen($reply) <= self::MAX_REPLY_CHARS) {
            return $reply;
        }

        $cut = mb_substr($reply, 0, self::MAX_REPLY_CHARS);

        $lastSentence = max(
            mb_strrpos($cut, '. ') ?: 0,
            mb_strrpos($cut, '! ') ?: 0,
            mb_strrpos($cut, '? ') ?: 0,
        );

        if ($lastSentence > self::MAX_REPLY_CHARS / 2) {
            return trim(mb_substr($cut, 0, $lastSentence + 1));
        }

        return trim($cut);
    }

    /**
     * Call Anthropic's messages API and return the text reply.
     *
     * Same shape as ProcessEmailWithAgentJob::callAnthropic — a shared
     * LLM client would be better and is worth doing once there are three
     * of these, but copying a 25-line HTTP call is cheaper right now
     * than designing an abstraction across two channels with different
     * needs.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function callAnthropic(string $systemPrompt, array $history): string
    {
        $apiKey = (string) config('services.anthropic.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Anthropic API key not configured');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('orbital.ai.default_llm_model', 'claude-sonnet-4-20250514'),
            // Deliberately small. The prompt asks for brevity; capping
            // output tokens makes a runaway generation cheap to fail
            // rather than expensive to truncate.
            'max_tokens' => 512,
            'system' => $systemPrompt,
            'messages' => $history,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Anthropic message reply call failed: '.$response->status().' '.$response->body()
            );
        }

        $text = '';

        foreach (($response->json('content') ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Anthropic returned an empty reply');
        }

        return $text;
    }
}
