<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Wraps Anthropic's messages API for the directory smart-ingest flow.
 *
 * The ingest conversation is a chain of user + assistant turns where
 * user messages can carry a text block and/or an image block (base64).
 * The assistant is instructed to reply with JSON-only output shaped
 * like a DirectoryEntry array, possibly with a short explanatory
 * message alongside.
 *
 * Two entry points:
 *   - `parse()` for a single-shot extraction with optional
 *     multimodal attachment
 *   - `revise()` for follow-up corrections where the operator chats
 *     back with instructions like "merge rows 2 and 3"
 *
 * Network concerns (auth, retries, JSON shape validation) live here
 * so the Livewire component upstream stays focused on UI state.
 */
class SmartIngestClient
{
    protected string $endpoint = 'https://api.anthropic.com/v1/messages';

    protected string $model = 'claude-opus-4-6';

    protected int $maxTokens = 4096;

    /**
     * Top-level instructions to the model. Applies to both the first
     * parse and all subsequent revisions. The schema block is built
     * from the client's own field definitions — no fixed shape is
     * baked in here so the same client serves dog walkers, lawyers,
     * and clinics with their wildly different directory schemas.
     * See {@see formatSchemaForPrompt()} for the block shape.
     */
    protected function systemPrompt(string $schemaBlock): string
    {
        return <<<PROMPT
You are a contact-parsing assistant for an answering-service platform. The operator will hand you raw input — CSV rows, an Excel sheet, a PDF, an image of a business card, an email forward, or free-text pasted from somewhere — and you extract it into structured directory entries for *this specific client*.

The target schema is defined by the client. Use ONLY these field keys:

{$schemaBlock}

Rules:
- Reply with a single JSON object, no prose, no code fences.
- Shape: {"rows": [ {..}, {..} ], "notes": "one-line human-readable summary of what you did"}
- Each row is keyed by the exact field key from the schema above. Omit fields you don't have data for; do not invent fields outside the schema.
- For select / multi_select fields, only emit values from the listed options (case-insensitive match). Skip the field if no option matches.
- For boolean fields, emit true or false.
- If multiple people are implied (a list, multiple cards, an org chart), return multiple rows.
- If a single entry is implied, return one row.
- Normalize phone numbers loosely (keep operator edits minimal).
- When a field has role=name, prefer extracting the full display name (don't split unless the schema also has separate first/last fields).
- If the operator asks for corrections, apply them to the prior row set and return the full updated row set — never a diff.
- If the input is ambiguous or empty, return {"rows": [], "notes": "…what's missing…"}.
PROMPT;
    }

    /**
     * Build the per-client schema block injected into the system
     * prompt. Each definition becomes one bullet line:
     *
     *     - {key} ({type}, role: {role}, required): {label}{help text}
     *
     * Select / multi_select types append their option list inline so
     * the model knows the closed set of valid values.
     *
     * @param  iterable<object>  $definitions  DirectoryFieldDefinition rows
     */
    public function formatSchemaForPrompt(iterable $definitions): string
    {
        $lines = [];
        foreach ($definitions as $def) {
            if (! ($def->is_active ?? true)) {
                continue;
            }
            $parts = [$def->type];
            if (($def->role ?? 'none') !== 'none') {
                $parts[] = 'role: '.$def->role;
            }
            if ($def->required) {
                $parts[] = 'required';
            }
            if (in_array($def->type, ['select', 'multi_select'], true) && is_array($def->options)) {
                $parts[] = 'one of: '.implode(', ', $def->options);
            }

            $line = '- '.$def->key.' ('.implode(', ', $parts).'): '.$def->label;
            if (filled($def->help_text)) {
                $line .= ' — '.$def->help_text;
            }
            $lines[] = $line;
        }

        return $lines === [] ? '(no fields defined yet)' : implode("\n", $lines);
    }

    /**
     * Initial parse. `$text` is the plain-text content (pasted input,
     * extracted PDF/email text, or stringified spreadsheet rows). If
     * `$imageBase64` is set, the image is attached as a multimodal
     * block so the model can OCR it — e.g. a photographed business
     * card or a scanned address list. `$schemaBlock` is the client's
     * field schema, formatted via {@see formatSchemaForPrompt()}.
     *
     * @return array{rows: array<int, array<string, mixed>>, notes: string}
     */
    public function parse(string $schemaBlock, string $text, ?string $imageBase64 = null, ?string $imageMediaType = null): array
    {
        $content = [];
        if ($imageBase64 !== null && $imageMediaType !== null) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $imageMediaType,
                    'data' => $imageBase64,
                ],
            ];
        }
        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => $text];
        }

        return $this->call($schemaBlock, [
            ['role' => 'user', 'content' => $content],
        ]);
    }

    /**
     * Follow-up turn. The operator's correction message is appended
     * to the existing transcript (system prompt + prior user/assistant
     * turns) so the model sees the whole history and returns a
     * revised row set.
     *
     * @param  array<int, array{role: string, content: mixed}>  $priorMessages
     * @return array{rows: array<int, array<string, mixed>>, notes: string}
     */
    public function revise(string $schemaBlock, array $priorMessages, string $correction): array
    {
        $messages = [...$priorMessages, ['role' => 'user', 'content' => $correction]];
        return $this->call($schemaBlock, $messages);
    }

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @return array{rows: array<int, array<string, mixed>>, notes: string}
     */
    protected function call(string $schemaBlock, array $messages): array
    {
        $apiKey = (string) config('services.anthropic.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured. Set it in .env to use smart ingest.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(90)
            ->post($this->endpoint, [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'system' => $this->systemPrompt($schemaBlock),
                'messages' => $messages,
            ]);

        if (! $response->successful()) {
            Log::warning('smart-ingest: anthropic call failed', [
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 500),
            ]);
            throw new RuntimeException('Anthropic API returned HTTP '.$response->status().' — '.$response->body());
        }

        $payload = $response->json();
        $text = $payload['content'][0]['text'] ?? '';
        if ($text === '') {
            throw new RuntimeException('Anthropic returned an empty response.');
        }

        return $this->parseJsonReply($text);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, notes: string}
     */
    protected function parseJsonReply(string $text): array
    {
        // Strip any accidental code fences just in case the model
        // wraps the JSON despite the system prompt saying not to.
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed) ?? $trimmed;

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Could not parse assistant reply as JSON. Raw text: '.substr($text, 0, 200));
        }

        return [
            'rows' => is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [],
            'notes' => (string) ($decoded['notes'] ?? ''),
        ];
    }
}
