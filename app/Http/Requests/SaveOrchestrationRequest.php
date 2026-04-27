<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ClientSlot;
use App\Models\IntakeFlowRule;
use App\Models\IntakeGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PUT /api/admin/flow-graph/{client}.
 *
 * The Svelte editor posts the entire graph on save — slots, flows
 * (with their steps + canvas positions), and transitions — and the
 * controller diffs this payload against the current state and
 * applies everything inside a DB transaction. Every piece is
 * treated as a candidate for create / update / delete based on
 * whether its `id` field matches an existing row.
 */
class SaveOrchestrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        $primitiveKeys = IntakeGoal::query()->pluck('key')->all();

        return [
            'slots' => ['array'],
            'slots.*.id' => ['nullable', 'integer'],
            'slots.*.name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'slots.*.type' => ['required', Rule::in(ClientSlot::TYPES)],
            'slots.*.choices' => ['nullable', 'array'],
            'slots.*.choices.*' => ['string'],
            'slots.*.description' => ['nullable', 'string', 'max:500'],

            'flows' => ['array'],
            'flows.*.id' => ['nullable', 'integer'],
            'flows.*.name' => ['required', 'string', 'max:160'],
            'flows.*.description' => ['nullable', 'string'],
            'flows.*.is_active' => ['boolean'],
            'flows.*.trigger_type' => ['nullable', 'string', 'max:32'],
            'flows.*.kind' => ['nullable', 'string', 'max:32'],
            'flows.*.display_order' => ['nullable', 'integer'],
            'flows.*.canvas_x' => ['nullable', 'integer'],
            'flows.*.canvas_y' => ['nullable', 'integer'],
            'flows.*.steps' => ['array'],
            'flows.*.steps.*.id' => ['nullable', 'integer'],
            'flows.*.steps.*.intake_goal_key' => ['required', 'string', Rule::in($primitiveKeys)],
            'flows.*.steps.*.position' => ['required', 'integer', 'min:0'],
            'flows.*.steps.*.step_params' => ['nullable', 'array'],

            'flows.*.rules' => ['array'],
            'flows.*.rules.*.id' => ['nullable', 'integer'],
            'flows.*.rules.*.step_id' => ['nullable', 'integer'],
            'flows.*.rules.*.trigger_event' => ['required', Rule::in(IntakeFlowRule::TRIGGERS)],
            'flows.*.rules.*.label' => ['nullable', 'string', 'max:160'],
            'flows.*.rules.*.condition' => ['nullable', 'string'],
            'flows.*.rules.*.action_prompt' => ['nullable', 'string'],
            'flows.*.rules.*.priority' => ['integer'],
            'flows.*.rules.*.is_active' => ['boolean'],

            'transitions' => ['array'],
            'transitions.*.id' => ['nullable', 'integer'],
            'transitions.*.from_flow_client_id' => ['required', 'string'], // client-side temp id into flows[] keyed by their payload id/tmp_id
            'transitions.*.to_flow_client_id' => ['nullable', 'string'],   // null = end call
            'transitions.*.condition' => ['nullable', 'array'],
            'transitions.*.description' => ['nullable', 'string', 'max:160'],
            'transitions.*.priority' => ['integer'],
            'transitions.*.source_handle' => ['nullable', 'string', 'max:32'],
        ];
    }
}
