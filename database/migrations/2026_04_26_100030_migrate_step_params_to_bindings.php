<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert every existing intake_flow_steps.step_params reference to a
 * team-scoped resource (agent persona, queue, extension, knowledge
 * store, DID set) into a binding-key string + a matching
 * orchestration_bindings row.
 *
 * Idempotent: skips fields whose current value is already a string
 * (treated as already-migrated binding key) or whose data_field type
 * is not in the picker map.
 *
 * `action_group_picker` references are intentionally skipped — they
 * point at flows inside the same orchestration and resolve correctly
 * even when the orchestration is shared across clients (flow IDs are
 * stable inside the orchestration's subtree).
 */
return new class extends Migration
{
    private const PICKER_TYPE_TO_RESOURCE = [
        'did_picker' => 'did_set',
        'extension_picker' => 'extension',
        'call_queue_picker' => 'call_queue',
        'email_queue_picker' => 'email_queue',
        'message_queue_picker' => 'message_queue',
        'chat_queue_picker' => 'chat_queue',
        'agent_persona_picker' => 'agent_persona',
        'knowledge_store_list' => 'knowledge_store',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $rows = DB::table('intake_flow_steps')
                ->join('intake_flows', 'intake_flows.id', '=', 'intake_flow_steps.flow_id')
                ->join('intake_goals', 'intake_goals.id', '=', 'intake_flow_steps.intake_goal_id')
                ->select(
                    'intake_flow_steps.id as step_id',
                    'intake_flow_steps.step_params',
                    'intake_flows.team_id',
                    'intake_flows.orchestration_id',
                    'intake_goals.data_fields',
                )
                ->cursor();

            foreach ($rows as $row) {
                $params = json_decode($row->step_params ?? 'null', true) ?? [];
                $fields = json_decode($row->data_fields ?? 'null', true) ?? [];

                if (! $row->orchestration_id || ! $row->team_id) {
                    continue;
                }

                $modified = false;

                foreach ($fields as $field) {
                    $type = $field['type'] ?? null;
                    $key = $field['key'] ?? null;
                    if (! $type || ! $key) {
                        continue;
                    }
                    if (! isset(self::PICKER_TYPE_TO_RESOURCE[$type])) {
                        continue;
                    }
                    if (! array_key_exists($key, $params)) {
                        continue;
                    }
                    $val = $params[$key];
                    if (is_string($val) || $val === null || $val === '' || $val === []) {
                        continue;
                    }

                    $resourceType = self::PICKER_TYPE_TO_RESOURCE[$type];
                    $bindingKey = "b_{$row->step_id}_{$key}";

                    $payload = [
                        'team_id' => $row->team_id,
                        'orchestration_id' => $row->orchestration_id,
                        'binding_key' => $bindingKey,
                        'resource_type' => $resourceType,
                        'resource_id' => null,
                        'resource_ids' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (is_array($val)) {
                        $payload['resource_ids'] = json_encode(
                            array_values(array_filter(
                                $val,
                                fn ($v) => $v !== null && $v !== '',
                            )),
                        );
                    } else {
                        $payload['resource_id'] = (int) $val;
                    }

                    DB::table('orchestration_bindings')->upsert(
                        [$payload],
                        ['team_id', 'orchestration_id', 'binding_key'],
                        ['resource_type', 'resource_id', 'resource_ids', 'updated_at'],
                    );

                    $params[$key] = $bindingKey;
                    $modified = true;
                }

                if ($modified) {
                    DB::table('intake_flow_steps')
                        ->where('id', $row->step_id)
                        ->update([
                            'step_params' => json_encode($params),
                            'updated_at' => now(),
                        ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Forward-only: the bindings table itself is dropped by the
        // create_orchestration_bindings_table down(), and step_params
        // values would already be inconsistent without the bindings.
    }
};
