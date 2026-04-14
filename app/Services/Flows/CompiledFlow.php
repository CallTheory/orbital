<?php

declare(strict_types=1);

namespace App\Services\Flows;

/**
 * Read-only value object that holds the fully-resolved output of
 * AgentFlowCompiler. Consumed by:
 *
 *   1. The Python agent worker, via the /agent-personas/by-extension API
 *      which serializes this object to JSON. The worker builds the LLM
 *      session from `llm_instructions` + `function_schemas` and registers
 *      `search_knowledge` whenever `available_stores` is non-empty.
 *
 *   2. The operator softphone Livewire component (Phase E), which reads
 *      `operator_view` directly to render talking points and a field form.
 *
 * Both surfaces consume the same compiled flow — the whole point of this
 * architecture is that the spec is authored once in intake_goals and
 * each renderer just walks it.
 */
final class CompiledFlow
{
    /**
     * @param  string  $llmInstructions  System prompt that will be passed to the LLM.
     * @param  array<int, array<string, mixed>>  $functionSchemas  JSON-schema-compatible function definitions the LLM may call.
     * @param  array<int, array{id: int, name: string, description: ?string}>  $availableStores  Knowledge stores the compiled flow is allowed to query.
     * @param  array<int, array<string, mixed>>  $operatorView  Ordered step list rendered by the operator UI.
     * @param  ?int  $flowId  The resolved IntakeFlow row (null if no flow was bound anywhere in the resolution chain).
     * @param  string  $resolutionSource  Where the flow came from: 'routing_rule', 'extension', 'persona', 'none'.
     */
    public function __construct(
        public readonly string $llmInstructions,
        public readonly array $functionSchemas,
        public readonly array $availableStores,
        public readonly array $operatorView,
        public readonly ?int $flowId,
        public readonly string $resolutionSource,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'llm_instructions' => $this->llmInstructions,
            'function_schemas' => $this->functionSchemas,
            'available_stores' => $this->availableStores,
            'operator_view' => $this->operatorView,
            'flow_id' => $this->flowId,
            'resolution_source' => $this->resolutionSource,
        ];
    }
}
