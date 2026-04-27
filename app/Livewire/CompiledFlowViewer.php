<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AgentPersona;
use App\Models\CallSessionState;
use App\Models\Extension;
use App\Services\Flows\AgentFlowCompiler;
use Livewire\Component;

/**
 * Operator-side renderer for a compiled intake flow. Reads the same
 * `operator_view` array the Python agent worker reads from
 * `function_schemas` + `llm_instructions`, so the operator and the AI
 * are always walking identical objectives in identical order.
 *
 * For v1 this is a stateful "checklist": the operator sees every step,
 * fills data fields, and clicks Next to advance. Mid-call AI→operator
 * handoff (pre-populated fields inherited from what the AI collected)
 * lands in a follow-up pass once the worker writes captured fields
 * back to a call-scoped store.
 */
class CompiledFlowViewer extends Component
{
    /** Extension number the active call is routed through — optional, drives the compile. */
    public ?string $extensionNumber = null;

    /** Persona ID override — when the viewer is mounted without an extension. */
    public ?int $personaId = null;

    /** Live call session key (LiveKit room name). When set, the viewer
     *  hydrates its field state from the shared call_session_states row
     *  so mid-call handoff shows whatever the AI already captured. */
    public ?string $sessionKey = null;

    /** Resolved compiled flow (plain arrays, not the PHP object — Livewire-safe). */
    public array $compiled = [];

    /** Position of the currently-active step inside the active flow. */
    public int $activeStep = 0;

    /** ID of the currently-active flow in the multi-flow operator_view. */
    public ?int $activeFlowId = null;

    /** Captured field state keyed by slot name. */
    public array $fields = [];

    public function mount(?string $extensionNumber = null, ?int $personaId = null, ?string $sessionKey = null): void
    {
        $this->extensionNumber = $extensionNumber;
        $this->personaId = $personaId;
        $this->sessionKey = $sessionKey;
        $this->loadCompiled();
        $this->loadSessionState();
    }

    /**
     * Pull captured fields + active step from the shared call session
     * row, if one exists. The AI writes to this table via its set_field
     * and advance_step tools, so by the time an operator takes over
     * mid-call their form state already reflects everything the AI
     * collected.
     */
    public function loadSessionState(): void
    {
        if (! $this->sessionKey) {
            return;
        }

        $state = CallSessionState::where('session_key', $this->sessionKey)->first();
        if (! $state) {
            return;
        }

        $this->fields = $state->fields ?? [];
        $this->activeStep = (int) ($state->active_step ?? 0);
    }

    /**
     * Run the AgentFlowCompiler for whatever resolution target the
     * component was mounted with. Falls back to the first active
     * persona in the user's team so the viewer is never blank during
     * dev — in production this is replaced by the live call's persona.
     */
    public function loadCompiled(): void
    {
        $extension = null;
        $persona = null;

        if ($this->extensionNumber) {
            $extension = Extension::withoutGlobalScopes()
                ->where('number', $this->extensionNumber)
                ->where('type', 'ai_agent')
                ->first();
            $persona = $extension?->assignable instanceof AgentPersona ? $extension->assignable : null;
        }

        if (! $persona && $this->personaId) {
            $persona = AgentPersona::withoutGlobalScope('team')->find($this->personaId);
        }

        // Dev fallback: grab any active client persona so the UI has
        // something to render while the softphone isn't handling a
        // real call. Remove this when the softphone wires a real
        // persona through.
        if (! $persona) {
            $persona = AgentPersona::withoutGlobalScope('team')
                ->whereNotNull('team_id')
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
        }

        if (! $persona) {
            $this->compiled = [];

            return;
        }

        $flow = app(AgentFlowCompiler::class)->compile($persona, $extension);
        $this->compiled = $flow->toArray();

        // operator_view is now a dict with `flows[]`. Default the
        // active flow to the entry, the active step to 0.
        $view = $this->compiled['operator_view'] ?? [];
        $this->activeFlowId = $view['entry_flow_id'] ?? null;
        $this->activeStep = 0;
        $this->fields = [];
    }

    /**
     * Advance within the currently-active flow. When the AI fires
     * `transition_to_flow`, the softphone switches `activeFlowId`
     * via a separate action (broadcast-wired later).
     */
    public function advanceStep(): void
    {
        $flow = $this->activeFlow();
        $max = max(0, count($flow['steps'] ?? []) - 1);
        if ($this->activeStep < $max) {
            $this->activeStep++;
        }
    }

    public function previousStep(): void
    {
        if ($this->activeStep > 0) {
            $this->activeStep--;
        }
    }

    public function switchFlow(int $flowId): void
    {
        $this->activeFlowId = $flowId;
        $this->activeStep = 0;
    }

    /**
     * Resolve the active flow dict from the compiled operator_view.
     *
     * @return array<string, mixed>
     */
    public function activeFlow(): array
    {
        $flows = $this->compiled['operator_view']['flows'] ?? [];
        foreach ($flows as $f) {
            if (($f['id'] ?? null) === $this->activeFlowId) {
                return $f;
            }
        }

        return $flows[0] ?? [];
    }

    public function render()
    {
        return view('livewire.compiled-flow-viewer');
    }
}
