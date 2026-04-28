<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\IntakeFlowStep;
use App\Models\IntakeGoal;
use App\Models\Orchestration;
use App\Models\OrchestrationBinding;
use DomainException;

/**
 * The indirection layer that lets one Orchestration row run against
 * many clients. Every team-scoped resource reference inside a step's
 * `step_params` (an agent persona, a queue, an extension, a knowledge
 * store, a DID set) is stored as a binding-key string. The matching
 * `orchestration_bindings` row maps that key (per-team, per-orchestration)
 * to a concrete resource_id (or array of ids for multi-pickers).
 *
 * Two directions:
 *   - `normalizeStepParams()` runs on save: turns concrete picker IDs
 *     coming from the editor into binding keys + upserts the matching
 *     binding row. Idempotent — already-stringified binding keys pass
 *     through untouched.
 *   - `resolveStepParams()` runs on load: turns binding keys back into
 *     concrete IDs for the editor's existing pickers. Per-client
 *     orchestrations resolve against the orchestration's own team;
 *     for platform-shared orchestrations there is no team context, so
 *     the binding keys are passed through unchanged and the editor
 *     renders binding-key inputs instead.
 */
class BindingResolver
{
    /**
     * Mapping from `intake_goals.data_fields[*].type` to a binding
     * `resource_type`. Only types that point at team-scoped resources
     * appear here. `action_group_picker` references flows inside the
     * same orchestration, which are stable across teams when the
     * orchestration is shared, so it's intentionally excluded.
     */
    public const PICKER_TYPE_TO_RESOURCE = [
        'did_picker' => OrchestrationBinding::TYPE_DID_SET,
        'extension_picker' => OrchestrationBinding::TYPE_EXTENSION,
        'call_queue_picker' => OrchestrationBinding::TYPE_CALL_QUEUE,
        'email_queue_picker' => OrchestrationBinding::TYPE_EMAIL_QUEUE,
        'message_queue_picker' => OrchestrationBinding::TYPE_MESSAGE_QUEUE,
        'chat_queue_picker' => OrchestrationBinding::TYPE_CHAT_QUEUE,
        'agent_persona_picker' => OrchestrationBinding::TYPE_AGENT_PERSONA,
        'knowledge_store_list' => OrchestrationBinding::TYPE_KNOWLEDGE_STORE,
    ];

    /**
     * Normalize a step's `step_params` array to binding keys, upserting
     * `orchestration_bindings` rows for every concrete picker value.
     *
     * For platform-shared orchestrations (team_id IS NULL) concrete
     * IDs aren't allowed — the caller must already have stringified
     * binding keys in place. We don't auto-create bindings for shared
     * orchestrations because there is no team to scope them to.
     *
     * @param  array<string, mixed>  $stepParams
     * @return array<string, mixed>
     */
    public function normalizeStepParams(
        IntakeFlowStep $step,
        IntakeGoal $goal,
        array $stepParams,
        Orchestration $orchestration,
    ): array {
        $fields = $goal->data_fields ?? [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? null;
            $key = $field['key'] ?? null;
            if (! $type || ! $key || ! isset(self::PICKER_TYPE_TO_RESOURCE[$type])) {
                continue;
            }
            if (! array_key_exists($key, $stepParams)) {
                continue;
            }

            $val = $stepParams[$key];

            if ($val === null || $val === '' || $val === []) {
                continue;
            }

            // Already a binding key — nothing to do.
            if (is_string($val)) {
                continue;
            }

            if ($orchestration->team_id === null) {
                throw new DomainException(
                    'Platform-shared orchestrations cannot accept concrete picker IDs in step_params. '.
                    "Field '{$key}' must hold a binding-key string."
                );
            }

            $resourceType = self::PICKER_TYPE_TO_RESOURCE[$type];
            $bindingKey = "b_{$step->id}_{$key}";

            $payload = [
                'team_id' => $orchestration->team_id,
                'orchestration_id' => $orchestration->id,
                'binding_key' => $bindingKey,
                'resource_type' => $resourceType,
                'resource_id' => null,
                'resource_ids' => null,
            ];

            if (is_array($val)) {
                $payload['resource_ids'] = array_values(array_filter(
                    $val,
                    fn ($v) => $v !== null && $v !== '',
                ));
            } else {
                $payload['resource_id'] = (int) $val;
            }

            OrchestrationBinding::updateOrCreate(
                [
                    'team_id' => $orchestration->team_id,
                    'orchestration_id' => $orchestration->id,
                    'binding_key' => $bindingKey,
                ],
                $payload,
            );

            $stepParams[$key] = $bindingKey;
        }

        return $stepParams;
    }

    /**
     * Reverse of `normalizeStepParams`. Walks each binding-key string
     * inside `step_params` and replaces it with the concrete
     * `resource_id` / `resource_ids` from the matching binding row.
     *
     * The caller supplies the bindings map (`bindingsByKey`) — usually
     * pre-loaded for whichever team the orchestration is running
     * against. The editor passes the orchestration's owning team's
     * bindings (so private orchestrations resolve concrete picker
     * values; shared orchestrations get an empty map and the
     * binding-key strings pass through to the binding-handle UI).
     * The runtime compiler passes the running client's bindings.
     *
     * @param  array<string, mixed>  $stepParams
     * @param  array<string, OrchestrationBinding>  $bindingsByKey  pre-loaded; an empty map causes binding keys to pass through unchanged
     * @return array<string, mixed>
     */
    public function resolveStepParams(
        IntakeGoal $goal,
        array $stepParams,
        array $bindingsByKey,
    ): array {
        if ($bindingsByKey === []) {
            return $stepParams;
        }

        $fields = $goal->data_fields ?? [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? null;
            $key = $field['key'] ?? null;
            if (! $type || ! $key || ! isset(self::PICKER_TYPE_TO_RESOURCE[$type])) {
                continue;
            }
            if (! array_key_exists($key, $stepParams)) {
                continue;
            }
            $val = $stepParams[$key];
            if (! is_string($val) || $val === '') {
                continue;
            }
            $binding = $bindingsByKey[$val] ?? null;
            if (! $binding) {
                // Missing binding row — null out so the consumer
                // surfaces a structured "unbound" error rather than
                // dispatching to the wrong resource.
                $stepParams[$key] = null;

                continue;
            }
            $stepParams[$key] = $binding->resource_ids !== null
                ? $binding->resource_ids
                : $binding->resource_id;
        }

        return $stepParams;
    }
}
