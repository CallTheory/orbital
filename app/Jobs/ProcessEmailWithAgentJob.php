<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AgentPersona;
use App\Models\EmailThread;
use App\Services\Flows\AgentFlowCompiler;
use App\Services\Mail\OutboundReplyService;
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
 * AI handoff for inbound email threads.
 *
 * Dispatched when:
 *   - An EmailRoutingRule points directly at an agent_persona
 *     (stamped on EmailThread::assigned_agent_persona_id at
 *     route time by ProcessInboundEmailJob)
 *   - An EmailQueue's overflow_agent_persona_id kicks in when no
 *     operator picks up (Phase 4 — not wired yet)
 *   - Operators explicitly escalate a thread to the AI (Phase 4)
 *
 * Text-in, text-out: reads the thread's messages, builds a
 * system prompt from the persona's compiled flow wrapped in
 * email-specific framing, calls Anthropic's messages API with
 * the conversation history projected into `user`/`assistant`
 * turns, takes the reply, hands to OutboundReplyService.
 *
 * No LiveKit, no Python worker — email lives entirely in
 * Laravel. Runs on the dedicated `inbound-mail` queue so it
 * shares lifecycle with the rest of the email pipeline.
 */
class ProcessEmailWithAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public int $threadId,
    ) {}

    public function handle(
        AgentFlowCompiler $compiler,
        OutboundReplyService $reply,
    ): void {
        $thread = EmailThread::with(['messages', 'team'])->find($this->threadId);
        if (! $thread) {
            Log::warning('agent email job: thread missing', ['thread_id' => $this->threadId]);
            return;
        }

        if (! $thread->assigned_agent_persona_id) {
            Log::warning('agent email job: no persona assigned', ['thread_id' => $this->threadId]);
            return;
        }

        $persona = AgentPersona::withoutGlobalScope('team')->find($thread->assigned_agent_persona_id);
        if (! $persona) {
            Log::warning('agent email job: persona missing', [
                'thread_id' => $this->threadId,
                'persona_id' => $thread->assigned_agent_persona_id,
            ]);
            return;
        }

        // Compile the flow fresh so we pick up any client-side
        // changes to intake goals / talking points between turns.
        $compiled = $compiler->compile($persona);

        // Email-specific wrapper around the persona's default
        // LLM instructions. The voice/intake instructions still
        // apply for content, but we add framing for the medium:
        // you're writing an email, keep it professional, don't
        // use stage-direction language, etc.
        $systemPrompt = $this->buildEmailSystemPrompt($compiled->llmInstructions ?? '', $persona, $thread);

        // Project the thread history into Anthropic's user/
        // assistant turn format. Inbound messages → user role,
        // outbound (our own prior replies) → assistant role.
        $history = [];
        foreach ($thread->messages as $msg) {
            $text = $msg->body_text ?: strip_tags($msg->body_html ?: '');
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $history[] = [
                'role' => $msg->direction === 'outbound' ? 'assistant' : 'user',
                'content' => $text,
            ];
        }

        if (empty($history)) {
            Log::warning('agent email job: empty history', ['thread_id' => $this->threadId]);
            return;
        }

        try {
            $replyBody = $this->callAnthropic($systemPrompt, $history);
        } catch (Throwable $e) {
            Log::error('agent email job: LLM call failed', [
                'thread_id' => $this->threadId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Hand off to OutboundReplyService, which builds the
        // threaded mail, sends it, and persists the outbound
        // EmailMessage row.
        $reply->reply(
            thread: $thread,
            bodyText: $replyBody,
            sentByOperator: null,
        );

        Log::info('agent email reply sent', [
            'thread_id' => $this->threadId,
            'persona_id' => $persona->id,
        ]);
    }

    /**
     * Wrap the persona's voice/intake LLM instructions with an
     * email-medium preamble. Keeps the persona's behavior rules
     * and client context while making sure the output is
     * email-shaped (no spoken-word artifacts, no stage directions).
     */
    private function buildEmailSystemPrompt(string $compiledInstructions, AgentPersona $persona, EmailThread $thread): string
    {
        $tenantName = $thread->team?->name ?? 'the customer';

        $preamble = <<<TEXT
You are responding to an email conversation as an AI agent for {$tenantName}.

Medium-specific rules:
- Write replies as a human would write an email: plain text, professional but warm
- No stage directions, no bracketed annotations, no "[speaks warmly]" or similar
- Sign off naturally
- Keep replies focused on the customer's most recent question or request
- If you don't have enough information to answer, say so and explain what you need

Persona: {$persona->name}
TEXT;

        if ($compiledInstructions !== '') {
            return $preamble."\n\nBehavior guidance:\n".$compiledInstructions;
        }

        return $preamble;
    }

    /**
     * Call Anthropic's messages API. Returns the model's text
     * reply as a single string. Pattern mirrors ChatService —
     * a shared LLM wrapper would be nicer but that refactor is
     * out of scope for Phase 3.
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
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 2048,
            'system' => $systemPrompt,
            'messages' => $history,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Anthropic email reply call failed: '.$response->status().' '.$response->body()
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
