<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\AgentPersona;
use App\Models\ChatSession;
use App\Services\Flows\AgentFlowCompiler;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The text-chat counterpart to the voice agent worker. Takes a user
 * message, feeds it through Anthropic with the compiled flow's
 * `llm_instructions` as the system prompt, and returns the assistant's
 * reply.
 *
 * v1 is deliberately no-tools: the chat surface gets a pure conversation
 * against the compiled flow instructions but doesn't yet execute
 * function calls (set_field, search_knowledge, etc). Adding tool use
 * requires a tool-use loop in PHP — an entirely tractable follow-up,
 * but intentionally deferred from this pass to ship the surface.
 */
class ChatService
{
    public function __construct(
        private readonly AgentFlowCompiler $compiler,
    ) {
    }

    public function handleTurn(ChatSession $session, string $userMessage): string
    {
        $persona = AgentPersona::withoutGlobalScope('team')->findOrFail($session->agent_persona_id);

        // Compile the flow fresh on every turn so the chat picks up any
        // changes made to the library or flow in between messages.
        $compiled = $this->compiler->compile($persona);
        $systemPrompt = $compiled->llmInstructions;

        $session->appendMessage('user', $userMessage);

        $reply = $this->callAnthropic($systemPrompt, $session->messages ?? []);

        $session->appendMessage('assistant', $reply);

        return $reply;
    }

    /**
     * Call the Anthropic messages API. Reads the API key from runtime
     * config (written by the Platform Settings page).
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    protected function callAnthropic(string $systemPrompt, array $history): string
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            return '[Chat is not configured — an Anthropic API key is required. Set it in Platform Settings → AI Providers.]';
        }

        // Anthropic doesn't accept the 'ts' field we store alongside
        // role/content, so project the history down to {role, content}.
        $messages = array_values(array_map(
            fn (array $m): array => [
                'role' => $m['role'] === 'user' ? 'user' : 'assistant',
                'content' => (string) ($m['content'] ?? ''),
            ],
            array_filter($history, fn (array $m) => in_array($m['role'] ?? '', ['user', 'assistant'], true)),
        ));

        if (empty($messages)) {
            return 'Hello! How can I help you?';
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'messages' => $messages,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Anthropic chat call failed: '.$response->status().' '.$response->body()
            );
        }

        $body = $response->json();
        $content = $body['content'] ?? [];

        // The messages API returns an array of content blocks — join
        // the text blocks together for our single-string reply.
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return trim($text) !== '' ? $text : '[No reply from the model]';
    }
}
