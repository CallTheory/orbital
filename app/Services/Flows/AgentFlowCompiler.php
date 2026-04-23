<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\AgentPersona;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeGoal;
use App\Models\KnowledgeStore;
use App\Models\RoutingRule;
use App\Services\Clients\TemplateResolver;

/**
 * Takes a persona + (optional) extension + (optional) routing rule and
 * returns a fully-resolved CompiledFlow that every surface can render.
 *
 * Flow resolution walks a fixed priority chain so there's a single
 * deterministic answer for "which flow does this call use":
 *
 *   routing_rule.intake_flow_id
 *     → extension.intake_flow_id
 *       → persona.default_flow_id
 *         → (none — the LLM just gets persona prompt + personality)
 *
 * The first non-null wins. A flow at any level replaces all levels below.
 */
class AgentFlowCompiler
{
    public function __construct(
        private readonly TemplateResolver $templates,
    ) {
    }

    public function compile(
        AgentPersona $persona,
        ?Extension $extension = null,
        ?RoutingRule $rule = null,
    ): CompiledFlow {
        $persona->loadMissing('template');

        [$flow, $source] = $this->resolveFlow($persona, $extension, $rule);

        if (! $flow) {
            return new CompiledFlow(
                llmInstructions: $this->personaPrompt($persona),
                functionSchemas: [],
                availableStores: [],
                operatorView: [],
                flowId: null,
                resolutionSource: 'none',
            );
        }

        $flow->loadMissing(['steps.intakeGoal']);

        $resolvedGoals = $flow->steps
            ->map(fn ($step) => $step->intakeGoal ? $this->resolveGoal($step->intakeGoal, $step->step_params ?? []) : null)
            ->filter()
            ->values();

        $llmInstructions = $this->personaPrompt($persona)
            ."\n\n"
            .$this->renderGoalsAsInstructions($resolvedGoals);

        $functionSchemas = $this->buildFunctionSchemas($resolvedGoals);

        $availableStores = $this->collectAvailableStores($resolvedGoals, $persona->team_id);

        if (! empty($availableStores)) {
            $functionSchemas[] = $this->searchKnowledgeSchema();

            // Teach the LLM how to cite from search_knowledge results.
            // The worker's search_knowledge tool formats results as
            // "[1] (source_ref) content…" blocks, so instructing the
            // model to cite with [1]/[2] keeps answers grounded and
            // auditable.
            $llmInstructions .= "\n\n"
                .'# Knowledge Citations'."\n"
                .'When you answer from search_knowledge results, cite each fact with the '
                .'bracketed number the search returned (e.g. "Our hours are 9-5 [1]."). '
                .'Only answer from content you actually retrieved — never invent a citation, '
                .'and never answer a knowledge question without searching first.';
        }

        $operatorView = $resolvedGoals->map(fn (array $goal) => [
            'key' => $goal['key'],
            'name' => $goal['name'],
            'description' => $goal['description'],
            'icon' => $goal['icon'],
            'talking_points' => $goal['talking_points'],
            'data_fields' => $goal['data_fields'],
            'completion' => $goal['completion'],
            'step_params' => $goal['step_params'] ?? [],
        ])->all();

        return new CompiledFlow(
            llmInstructions: $llmInstructions,
            functionSchemas: $functionSchemas,
            availableStores: $availableStores,
            operatorView: $operatorView,
            flowId: $flow->id,
            resolutionSource: $source,
        );
    }

    /**
     * @return array{0: ?IntakeFlow, 1: string}
     */
    private function resolveFlow(AgentPersona $persona, ?Extension $extension, ?RoutingRule $rule): array
    {
        if ($rule?->intake_flow_id) {
            return [IntakeFlow::withoutGlobalScope('team')->find($rule->intake_flow_id), 'routing_rule'];
        }
        if ($extension?->intake_flow_id) {
            return [IntakeFlow::withoutGlobalScope('team')->find($extension->intake_flow_id), 'extension'];
        }
        if ($persona->default_flow_id) {
            return [IntakeFlow::withoutGlobalScope('team')->find($persona->default_flow_id), 'persona'];
        }
        return [null, 'none'];
    }

    /**
     * Build the persona-level system prompt — personality + greeting +
     * stock system instructions. Walks the template resolver so the
     * instance's overrides (if any) win over the template's values.
     */
    private function personaPrompt(AgentPersona $persona): string
    {
        $parts = [];

        $personality = $persona->effectiveField('personality');
        if (filled($personality)) {
            $parts[] = "# Personality\n".$personality;
        }

        $systemPrompt = $persona->effectiveField('system_prompt');
        if (filled($systemPrompt)) {
            $parts[] = $systemPrompt;
        }

        $greeting = $persona->effectiveField('greeting');
        if (filled($greeting)) {
            $parts[] = "# Greeting\nWhen the call connects, say: {$greeting}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Merge the library primitive's defaults with the per-step params
     * this placement carries. Step params win for any key they set;
     * unset keys fall through to the library row.
     *
     * Two keys get special handling because they're arrays whose natural
     * merge semantics aren't "replace":
     *   - `knowledge_store_ids`: step value replaces (author explicitly
     *     picked which stores this node should search; empty means none).
     *   - `data_fields`: unchanged from the library — it describes the
     *     PARAM schema for the editor, not runtime behavior, so overriding
     *     it would confuse the UI.
     *
     * @param  array<string, mixed>  $stepParams
     * @return array<string, mixed>
     */
    private function resolveGoal(IntakeGoal $goal, array $stepParams = []): array
    {
        $base = $goal->attributesToArray();

        $merged = $stepParams + $base;
        $merged['id'] = $goal->id;
        $merged['key'] = $goal->key;
        // Always read data_fields from the library row — it's the schema
        // documentation for the editor, not runtime content.
        $merged['data_fields'] = $goal->data_fields ?? [];
        // Expose the raw step params under a dedicated key so downstream
        // renderers (prompt builder, operator script) can reach the
        // per-placement configuration without round-tripping the merge.
        $merged['step_params'] = $stepParams;
        $merged['knowledge_store_ids'] = $stepParams['knowledge_store_ids']
            ?? $goal->knowledge_store_ids
            ?? [];

        return $merged;
    }

    /**
     * Render the full ordered goal sequence into a human-readable
     * instructions block for the LLM. The agent sees this ONCE as part
     * of its system prompt and is expected to advance through steps
     * linearly (branching is handled client-side by parsing function
     * call results).
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $goals
     */
    private function renderGoalsAsInstructions($goals): string
    {
        $lines = ['# Intake Objectives', ''];
        $lines[] = 'Walk through these objectives in order. Advance to the next one when the current one is satisfied according to its completion criteria. Do not skip ahead.';
        $lines[] = '';

        foreach ($goals as $i => $goal) {
            $num = $i + 1;
            $lines[] = "## {$num}. {$goal['name']}";
            if (! empty($goal['description'])) {
                $lines[] = $goal['description'];
            }

            if (! empty($goal['talking_points'])) {
                $lines[] = '';
                $lines[] = 'Say or ask:';
                foreach ($goal['talking_points'] as $point) {
                    $text = is_array($point) ? ($point['text'] ?? '') : $point;
                    if (filled($text)) {
                        $lines[] = "- {$text}";
                    }
                }
            }

            if (! empty($goal['data_fields'])) {
                $lines[] = '';
                $lines[] = 'Collect these fields by calling `set_field(key, value)` as soon as you have the information:';
                foreach ($goal['data_fields'] as $field) {
                    $k = $field['key'] ?? '';
                    $l = $field['label'] ?? $k;
                    $req = ($field['required'] ?? false) ? ' (required)' : '';
                    $hint = ! empty($field['hint']) ? " — {$field['hint']}" : '';
                    $lines[] = "- `{$k}` — {$l}{$req}{$hint}";
                }
            }

            $completion = $goal['completion'] ?? [];
            $type = is_array($completion) ? ($completion['type'] ?? 'manual') : 'manual';
            $lines[] = '';
            $lines[] = match ($type) {
                'all_required' => 'Complete this objective once every required field above is captured.',
                'decision' => "Complete this objective once you've called `advance_step` with decision=true.",
                default => 'Use your judgment to move on when the objective is satisfied.',
            };
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Build one function schema per data_field across all goals, plus
     * a generic `advance_step` the LLM calls to move to the next goal,
     * plus any bound tools.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $goals
     * @return array<int, array<string, mixed>>
     */
    private function buildFunctionSchemas($goals): array
    {
        $schemas = [];

        // Generic field-capture tool — the LLM calls this whenever it
        // collects a data field. We hand it (key, value) pairs and
        // reconcile server-side.
        $schemas[] = [
            'name' => 'set_field',
            'description' => 'Record a collected data field from the caller. Use the exact key from the active objective\'s data_fields list.',
            'parameters' => [
                'type' => 'object',
                'required' => ['key', 'value'],
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'The data_field key.'],
                    'value' => ['type' => 'string', 'description' => 'The captured value as text.'],
                ],
            ],
        ];

        $schemas[] = [
            'name' => 'advance_step',
            'description' => 'Advance from the current objective to the next one. Only call after the current objective\'s completion criteria are met.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'decision' => ['type' => 'boolean', 'description' => 'For decision-type goals, whether the decision condition was met.'],
                    'reason' => ['type' => 'string', 'description' => 'Optional one-line reason for advancing.'],
                ],
            ],
        ];

        // Goal-level tool bindings (transfer_call, lookup_account, ...)
        $seen = [];
        foreach ($goals as $goal) {
            foreach ($goal['tools'] ?? [] as $tool) {
                $type = is_array($tool) ? ($tool['type'] ?? null) : null;
                if (! $type || isset($seen[$type])) {
                    continue;
                }
                $seen[$type] = true;
                $schemas[] = $this->toolSchema($type);
            }
        }

        return array_values(array_filter($schemas));
    }

    /**
     * Stock schema for a named tool binding. Worker-side code maps the
     * function call back to concrete behavior.
     */
    private function toolSchema(string $type): ?array
    {
        return match ($type) {
            'transfer_call' => [
                'name' => 'transfer_call',
                'description' => 'Transfer the live call to a specific extension, number, or department.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['destination'],
                    'properties' => [
                        'destination' => ['type' => 'string'],
                        'mode' => ['type' => 'string', 'enum' => ['cold', 'warm']],
                        'reason' => ['type' => 'string'],
                    ],
                ],
            ],
            'lookup_account' => [
                'name' => 'lookup_account',
                'description' => 'Look up an existing customer account by name, phone, email, or account number.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['lookup_value'],
                    'properties' => [
                        'lookup_value' => ['type' => 'string'],
                    ],
                ],
            ],
            'send_sms' => [
                'name' => 'send_sms',
                'description' => 'Send an SMS message to a phone number.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['to', 'body'],
                    'properties' => [
                        'to' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                    ],
                ],
            ],
            default => null,
        };
    }

    /**
     * Collect the distinct set of knowledge stores referenced by any goal
     * in the flow. Any store_id that doesn't belong to the persona's team
     * is silently dropped — a defense-in-depth check against misconfigured
     * client data.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $goals
     * @return array<int, array{id: int, name: string, description: ?string}>
     */
    private function collectAvailableStores($goals, ?int $teamId): array
    {
        $ids = $goals
            ->flatMap(fn (array $g) => $g['knowledge_store_ids'] ?? [])
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty() || ! $teamId) {
            return [];
        }

        return KnowledgeStore::withoutGlobalScope('team')
            ->where('team_id', $teamId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get(['id', 'name', 'description'])
            ->map(fn (KnowledgeStore $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
            ])
            ->all();
    }

    /**
     * Shared schema for the `search_knowledge` tool. Only included when
     * the compiled flow has at least one reachable knowledge store.
     */
    private function searchKnowledgeSchema(): array
    {
        return [
            'name' => 'search_knowledge',
            'description' => 'Search the client\'s knowledge stores for an answer. Use this when the caller asks a factual question that might be answered from FAQ or policy documents. The response includes the most relevant chunks with citations; answer ONLY from what you find.',
            'parameters' => [
                'type' => 'object',
                'required' => ['query'],
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The search query in natural language.'],
                    'top_k' => ['type' => 'integer', 'description' => 'How many chunks to return (default 5, max 20).'],
                ],
            ],
        ];
    }
}
