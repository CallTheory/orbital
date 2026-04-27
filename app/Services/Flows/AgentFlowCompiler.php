<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\AgentPersona;
use App\Models\ClientSlot;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowRule;
use App\Models\IntakeFlowTransition;
use App\Models\IntakeGoal;
use App\Models\KnowledgeStore;
use App\Models\OrchestrationBinding;
use App\Models\RoutingRule;
use Illuminate\Support\Collection;

// Expression services live in the same namespace; no `use` needed,
// but keeping the references explicit aids grep-ability.

/**
 * Takes a persona + (optional) extension + (optional) routing rule and
 * returns a fully-resolved CompiledFlow the voice worker, operator UI,
 * email job, and chat service can all ingest.
 *
 * Flow resolution walks a fixed priority chain to pick the ENTRY flow:
 *
 *   routing_rule.intake_flow_id
 *     → extension.intake_flow_id
 *       → persona.default_flow_id
 *         → (none — persona prompt only)
 *
 * From the entry flow we BFS outbound transitions to collect every
 * reachable flow and ship them all in one compiled blob. The LLM sees
 * the whole graph up front and follows transition rules stated in
 * plain English inside the prompt — no server-side state engine.
 */
class AgentFlowCompiler
{
    /**
     * The team whose bindings we resolve picker fields against. Set
     * inside `compile()` from `$persona->team_id` and read by every
     * step-params resolution. Distinct from the orchestration's
     * owning team (which is null for platform-shared orchestrations).
     */
    private ?int $runningTeamId = null;

    /**
     * Bindings keyed by `(orchestration_id => binding_key => row)`.
     * Cached for one compile pass so each unique orchestration only
     * hits the DB once even when an orchestration's flows fan out
     * across many compileOneFlow calls.
     *
     * @var array<int, array<string, OrchestrationBinding>>
     */
    private array $bindingsByOrchestration = [];

    public function __construct(
        private readonly JsonLogicRenderer $conditionRenderer,
        private readonly TemplateEvaluator $templates = new TemplateEvaluator,
        private readonly BindingResolver $bindings = new BindingResolver,
    ) {}

    /**
     * Render a step-param value for inclusion in the LLM prompt. The
     * value is a plain string that may contain `{{ expression }}`
     * placeholders — those are rewritten as backticked refs the LLM
     * will substitute at speak time (e.g. `{{ caller_name }}` →
     * `` `caller_name` ``). Bare strings pass through unchanged.
     */
    private function renderParam(mixed $raw): string
    {
        if (! is_string($raw) || $raw === '') {
            return '';
        }

        return $this->templates->renderForPrompt($raw);
    }

    public function compile(
        AgentPersona $persona,
        ?Extension $extension = null,
        ?RoutingRule $rule = null,
    ): CompiledFlow {
        $persona->loadMissing('template');

        $this->runningTeamId = $persona->team_id;
        $this->bindingsByOrchestration = [];

        [$entryFlow, $source] = $this->resolveEntryFlow($persona, $extension, $rule);

        if (! $entryFlow) {
            return new CompiledFlow(
                llmInstructions: $this->personaPrompt($persona),
                functionSchemas: [],
                availableStores: [],
                operatorView: [],
                flowId: null,
                resolutionSource: 'none',
            );
        }

        $reachableFlows = $this->collectReachableFlows($entryFlow);
        $slots = $this->collectSlots($persona->team_id);

        /** @var Collection<int, array<string, mixed>> $compiledFlows */
        $compiledFlows = $reachableFlows->map(fn (IntakeFlow $f) => $this->compileOneFlow($f));

        $llmInstructions = $this->renderFullPrompt($persona, $entryFlow, $compiledFlows, $slots);

        $resolvedGoals = $compiledFlows->flatMap(fn (array $f) => $f['goals'])->values();
        $functionSchemas = $this->buildFunctionSchemas($resolvedGoals, $compiledFlows);

        $availableStores = $this->collectAvailableStores($resolvedGoals, $persona->team_id);

        if (! empty($availableStores)) {
            $functionSchemas[] = $this->searchKnowledgeSchema();
            $llmInstructions .= "\n\n# Knowledge Citations\n"
                .'When you answer from search_knowledge results, cite each fact with the '
                .'bracketed number the search returned (e.g. "Our hours are 9-5 [1]."). '
                .'Only answer from content you actually retrieved — never invent a citation, '
                .'and never answer a knowledge question without searching first.';
        }

        $operatorView = $this->buildOperatorView($entryFlow, $compiledFlows, $slots);

        return new CompiledFlow(
            llmInstructions: $llmInstructions,
            functionSchemas: $functionSchemas,
            availableStores: $availableStores,
            operatorView: $operatorView,
            flowId: $entryFlow->id,
            resolutionSource: $source,
        );
    }

    /**
     * @return array{0: ?IntakeFlow, 1: string}
     */
    private function resolveEntryFlow(AgentPersona $persona, ?Extension $extension, ?RoutingRule $rule): array
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
     * BFS from the entry flow through outbound transitions to collect
     * every flow the LLM might land in during this call.
     *
     * @return Collection<int, IntakeFlow>
     */
    private function collectReachableFlows(IntakeFlow $entry): Collection
    {
        /** @var array<int, IntakeFlow> $found */
        $found = [$entry->id => $entry];
        $queue = [$entry->id];

        while (! empty($queue)) {
            $currentId = array_shift($queue);
            $outbound = IntakeFlowTransition::query()
                ->where('from_flow_id', $currentId)
                ->whereNotNull('to_flow_id')
                ->pluck('to_flow_id')
                ->unique();

            $newIds = $outbound->diff(array_keys($found))->all();
            if ($newIds === []) {
                continue;
            }

            $flows = IntakeFlow::withoutGlobalScope('team')
                ->whereIn('id', $newIds)
                ->get();
            foreach ($flows as $f) {
                $found[$f->id] = $f;
                $queue[] = $f->id;
            }
        }

        $entry->loadMissing(['steps.intakeGoal', 'transitionsOut']);
        foreach ($found as $f) {
            $f->loadMissing(['steps.intakeGoal', 'transitionsOut']);
        }

        // Pull in any action groups any reachable flow invokes via
        // `call_action_group`. This is separate from the transition
        // BFS because action groups aren't "transitioned to" — the
        // caller flow temporarily borrows the group's steps and
        // returns. Without this pass the LLM wouldn't see the
        // group's full instructions.
        $this->includeInvokedActionGroups($found);

        return collect(array_values($found));
    }

    /**
     * Walk every reachable flow's steps, find `call_action_group`
     * invocations, and make sure the referenced flows are in $found.
     * Runs to fixpoint so action groups that invoke other action
     * groups also get pulled in.
     *
     * @param  array<int, IntakeFlow>  $found  mutated in place
     */
    private function includeInvokedActionGroups(array &$found): void
    {
        while (true) {
            $needed = [];
            foreach ($found as $flow) {
                foreach ($flow->steps as $step) {
                    $goal = $step->intakeGoal;
                    if ($goal?->key !== 'call_action_group') {
                        continue;
                    }
                    $targetId = (int) ($step->step_params['action_group_flow_id'] ?? 0);
                    if ($targetId > 0 && ! isset($found[$targetId])) {
                        $needed[$targetId] = true;
                    }
                }
            }
            if ($needed === []) {
                return;
            }
            $more = IntakeFlow::withoutGlobalScope('team')
                ->whereIn('id', array_keys($needed))
                ->with(['steps.intakeGoal', 'transitionsOut'])
                ->get();
            foreach ($more as $f) {
                $found[$f->id] = $f;
            }
        }
    }

    /**
     * Resolve one flow's steps + transitions into a dict the renderer
     * can consume.
     *
     * @return array{flow: IntakeFlow, goals: Collection<int, array<string, mixed>>, transitions: Collection<int, array<string, mixed>>}
     */
    private function compileOneFlow(IntakeFlow $flow): array
    {
        $bindingsByKey = $this->bindingsFor($flow);

        /** @var Collection<int, array<string, mixed>> $goals */
        $goals = $flow->steps
            ->map(function ($step) use ($bindingsByKey) {
                if (! $step->intakeGoal) {
                    return null;
                }
                $resolved = $this->bindings->resolveStepParams(
                    $step->intakeGoal,
                    $step->step_params ?? [],
                    $bindingsByKey,
                );

                return $this->resolveGoal($step->intakeGoal, $resolved);
            })
            ->filter()
            ->values();

        /** @var Collection<int, array<string, mixed>> $transitions */
        $transitions = $flow->transitionsOut
            ->sortBy('priority')
            ->values()
            ->map(function (IntakeFlowTransition $t) {
                return [
                    'id' => $t->id,
                    'to_flow_id' => $t->to_flow_id,
                    'to_flow_name' => $t->toFlow?->name,
                    'condition_human' => $this->conditionRenderer->render($t->condition),
                    'condition' => $t->condition,
                    'description' => $t->description,
                    'priority' => $t->priority,
                    'ends_call' => $t->to_flow_id === null,
                    'is_fallback' => $t->condition === null || $t->condition === [],
                ];
            });

        $flow->loadMissing('rules');
        /** @var Collection<int, array<string, mixed>> $rules */
        $rules = $flow->rules
            ->where('is_active', true)
            ->sortBy('priority')
            ->values()
            ->map(fn (IntakeFlowRule $r) => [
                'id' => $r->id,
                'step_id' => $r->step_id,
                'trigger_event' => $r->trigger_event,
                'label' => $r->label,
                'condition' => $r->condition,
                'action_prompt' => $r->action_prompt,
                'priority' => $r->priority,
            ]);

        return [
            'flow' => $flow,
            'goals' => $goals,
            'transitions' => $transitions,
            'rules' => $rules,
        ];
    }

    /**
     * Bindings for a given flow's parent orchestration, keyed by
     * binding_key. Pulled against the running team (the persona's
     * owning client). For platform-shared orchestrations this lookup
     * yields the running client's bindings; for per-client orchestrations
     * the running team and the orchestration's owning team are the
     * same row.
     *
     * @return array<string, OrchestrationBinding>
     */
    private function bindingsFor(IntakeFlow $flow): array
    {
        if ($this->runningTeamId === null) {
            return [];
        }
        $orchId = $flow->orchestration_id;
        if (! $orchId) {
            return [];
        }
        if (! isset($this->bindingsByOrchestration[$orchId])) {
            $this->bindingsByOrchestration[$orchId] = OrchestrationBinding::query()
                ->withoutGlobalScope('team')
                ->where('team_id', $this->runningTeamId)
                ->where('orchestration_id', $orchId)
                ->get()
                ->keyBy('binding_key')
                ->all();
        }

        return $this->bindingsByOrchestration[$orchId];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function collectSlots(?int $teamId): Collection
    {
        if (! $teamId) {
            return collect();
        }

        return ClientSlot::query()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get()
            ->map(fn (ClientSlot $s) => [
                'name' => $s->name,
                'type' => $s->type,
                'choices' => $s->choices,
                'description' => $s->description,
            ]);
    }

    /**
     * Render persona prompt + variables section + one section per
     * reachable flow with its steps and transition rules.
     *
     * @param  Collection<int, array<string, mixed>>  $compiledFlows
     * @param  Collection<int, array<string, mixed>>  $slots
     */
    private function renderFullPrompt(
        AgentPersona $persona,
        IntakeFlow $entry,
        Collection $compiledFlows,
        Collection $slots,
    ): string {
        $lines = [$this->personaPrompt($persona)];

        if ($slots->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '# Variables available during this call';
            foreach ($slots as $slot) {
                $type = $slot['type'];
                if ($type === 'choice' && ! empty($slot['choices'])) {
                    $type .= ': '.implode(' | ', $slot['choices']);
                }
                $desc = ! empty($slot['description']) ? ' — '.$slot['description'] : '';
                $lines[] = sprintf('- `%s` (%s)%s', $slot['name'], $type, $desc);
            }
        }

        $lines[] = '';
        $lines[] = '# How this call works';
        $lines[] = sprintf(
            'Start in flow "%s". After completing a flow\'s steps, consult its "Next step" rules and move to the matching flow. When a flow ends the call, say goodbye politely and hang up.',
            $entry->name
        );

        foreach ($compiledFlows as $cf) {
            $lines[] = '';
            $lines = array_merge($lines, $this->renderFlowSection($cf));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $compiled
     * @return array<int, string>
     */
    private function renderFlowSection(array $compiled): array
    {
        /** @var IntakeFlow $flow */
        $flow = $compiled['flow'];
        /** @var Collection<int, array<string, mixed>> $goals */
        $goals = $compiled['goals'];
        /** @var Collection<int, array<string, mixed>> $transitions */
        $transitions = $compiled['transitions'];

        $lines = [];
        $lines[] = "## Flow: {$flow->name}";
        if (filled($flow->description)) {
            $lines[] = $flow->description;
        }

        if ($goals->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Steps (run in order):';
            foreach ($goals as $i => $goal) {
                $num = $i + 1;
                $lines[] = "  {$num}. [{$goal['key']}] ".$this->describeStep($goal);
            }
        }

        if ($transitions->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Next step:';
            foreach ($transitions as $i => $t) {
                $num = $i + 1;
                $target = $t['ends_call']
                    ? 'end the call politely'
                    : 'go to flow "'.$t['to_flow_name'].'"';
                $gate = $t['is_fallback']
                    ? 'Otherwise'
                    : 'If '.$t['condition_human'];
                $label = $t['description'] ? " ({$t['description']})" : '';
                $lines[] = "  {$num}. {$gate} → {$target}{$label}";
            }
        } else {
            $lines[] = '';
            $lines[] = 'When this flow is done, end the call politely.';
        }

        /** @var Collection<int, array<string, mixed>> $rules */
        $rules = $compiled['rules'] ?? collect();
        if ($rules->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Reactive rules (fire continuously as the flow progresses):';
            foreach ($rules as $i => $r) {
                $num = $i + 1;
                $trigger = $this->humanizeRuleTrigger($r['trigger_event']);
                $condition = $r['condition']
                    ? ' AND '.$r['condition']
                    : '';
                $action = $this->templates->renderForPrompt((string) ($r['action_prompt'] ?? ''));
                if ($action === '') {
                    $action = '(no action configured)';
                }
                $labelPart = $r['label'] ? '"'.$r['label'].'" — ' : '';
                $lines[] = "  {$num}. {$labelPart}When {$trigger}{$condition}, do: {$action}";
            }
        }

        return $lines;
    }

    /**
     * Humanise a rule trigger enum into the opening clause of its
     * natural-language sentence in the prompt.
     */
    private function humanizeRuleTrigger(string $event): string
    {
        return match ($event) {
            IntakeFlowRule::TRIGGER_ON_FIELD_SET => 'a slot is set',
            IntakeFlowRule::TRIGGER_ON_STEP_ENTER => 'a step starts',
            IntakeFlowRule::TRIGGER_ON_STEP_COMPLETE => 'a step finishes',
            IntakeFlowRule::TRIGGER_ON_FLOW_START => 'the flow begins',
            IntakeFlowRule::TRIGGER_ON_FLOW_END => 'the flow ends',
            default => $event,
        };
    }

    /**
     * One-line imperative summary of a step for the LLM prompt.
     *
     * @param  array<string, mixed>  $goal
     */
    private function describeStep(array $goal): string
    {
        $name = $goal['name'] ?? $goal['key'];
        $key = (string) ($goal['key'] ?? '');
        $params = $goal['step_params'] ?? [];
        $hint = '';

        // Surface the most useful param inline so the prompt reads
        // naturally. gather_* primitives show their slot,
        // save_message its included slots, transfer_call its destination.
        if (! empty($params['slot'])) {
            $hint = ' → slot `'.$params['slot'].'`';
        } elseif (! empty($params['include_slots']) && is_array($params['include_slots'])) {
            $hint = ' → include: '.implode(', ', $params['include_slots']);
        } elseif (! empty($params['destination'])) {
            $hint = ' → '.$params['destination'];
        }

        $extra = '';
        if (! empty($params['prompt'])) {
            $rendered = $this->renderParam($params['prompt']);
            if ($rendered !== '') {
                $extra = ' — say: "'.$rendered.'"';
            }
        }

        $typeHint = $this->typeHintFor($key, is_array($params) ? $params : []);
        if ($typeHint !== '') {
            $extra .= ' — '.$typeHint;
        }

        return $name.$hint.$extra;
    }

    /**
     * Per-primitive shape hint embedded in the step's one-line summary.
     * Keeps the LLM oriented to the expected slot shape without
     * bloating the prompt with full talking-points.
     *
     * @param  array<string, mixed>  $params
     */
    private function typeHintFor(string $key, array $params): string
    {
        return match ($key) {
            'call_action_group' => $this->actionGroupHint($params),
            'send_email' => $this->sendHint('email', $params, 'subject', 'body'),
            'send_sms' => $this->sendHint('SMS', $params, null, 'body'),
            'send_page' => $this->sendHint('page', $params, null, 'body'),
            'send_fax' => $this->sendHint('fax', $params, 'subject', 'body'),
            'send_wctp' => $this->sendHint('WCTP message', $params, null, 'body'),
            'mark_message_sent' => 'record that the referenced message was sent.',
            'mark_message_delivered' => 'record that the referenced message was delivered (read / acknowledged).',
            'db_lookup_single' => $this->dbHint('look up a single row', $params),
            'db_picklist' => $this->dbHint('offer the caller a pick-list of rows', $params),
            'db_iterate' => $this->dbHint('iterate over rows and invoke the body action group for each', $params),
            'db_save' => isset($params['table']) && $params['table'] !== ''
                ? 'upsert into table `'.$params['table'].'` via connection "'.($params['connection_name'] ?? '(none)').'".'
                : 'upsert into a DB table (connection + table not yet chosen).',
            'web_call' => $this->webHint($params),
            'parse_json' => 'parse the source slot\'s JSON and extract fields via JSONPath.',
            'parse_xml' => 'parse the source slot\'s XML and extract fields via XPath.',
            'enter_dispatcher_queue' => isset($params['queue_name']) && $params['queue_name'] !== ''
                ? 'post the call to dispatcher queue "'.$params['queue_name'].'" — a human operator picks up from here.'
                : 'post the call to a dispatcher queue (not yet configured).',
            'auto_dispatch' => isset($params['rule_name']) && $params['rule_name'] !== ''
                ? 'fire auto-dispatch rule "'.$params['rule_name'].'" — notify the on-call roster without operator intervention.'
                : 'fire an auto-dispatch rule (not yet configured).',
            'handle_dispatch_event' => 'accept an incoming dispatch; bind its context to slots.',
            'park_call' => $this->parkCallHint($params),
            'pause' => isset($params['seconds']) && (int) $params['seconds'] > 0
                ? 'pause for '.(int) $params['seconds'].' second'.((int) $params['seconds'] === 1 ? '' : 's').'.'
                : 'pause briefly.',
            'toggle_hold' => 'toggle call hold ('.($params['state'] ?? 'toggle').').',
            'toggle_recording' => 'toggle recording ('.($params['state'] ?? 'toggle').').',
            'play_hold_music' => isset($params['seconds']) && (int) $params['seconds'] > 0
                ? 'play hold music for '.(int) $params['seconds'].' seconds.'
                : 'play hold music until the next step.',
            'change_account' => 'switch to a different account context mid-call.',
            'transfer_to_voicemail' => isset($params['mailbox']) && $params['mailbox'] !== ''
                ? 'send caller to voicemail box "'.$params['mailbox'].'" (terminal).'
                : 'send caller to voicemail (mailbox not yet chosen).',
            'save_summary' => $this->summaryHint($params),
            'save_keyword' => isset($params['keyword_slug']) && $params['keyword_slug'] !== ''
                ? 'tag the call with keyword "'.$params['keyword_slug'].'".'.(
                    ! empty($params['condition'])
                        ? ' Only tag if '.$this->renderParam($params['condition']).'.'
                        : ''
                )
                : 'tag the call with a keyword (not yet chosen).',
            'save_call_tracker_event' => isset($params['event_type']) && $params['event_type'] !== ''
                ? 'emit call tracker event `'.$params['event_type'].'`'.(
                    ! empty($params['description'])
                        ? ' — '.$this->renderParam($params['description'])
                        : ''
                ).'.'
                : 'emit a call tracker event (event_type not yet chosen).',
            'save_history' => 'append a history entry ('.($params['scope'] ?? 'caller').' scope)'.(
                ! empty($params['subject'])
                    ? ' — '.$this->renderParam($params['subject'])
                    : ''
            ).'.',
            'get_history' => 'retrieve recent history entries ('.($params['scope'] ?? 'caller').' scope)'.(
                isset($params['max_entries']) && (int) $params['max_entries'] > 0
                    ? ', up to '.(int) $params['max_entries']
                    : ''
            ).'.',
            'answer_question' => 'search the attached knowledge stores; take the `answered` exit if you find an answer, else take the `no_answer` exit.',
            'gather_phone' => 'collect as E.164 phone (e.g. +15551234567).',
            'gather_email' => 'collect a valid email (name@domain.tld).',
            'gather_number' => $this->numberHint($params),
            'gather_date' => $this->rangeHint('collect an ISO date (YYYY-MM-DD)', $params, 'min_date', 'max_date'),
            'gather_datetime' => 'collect an ISO datetime (YYYY-MM-DDTHH:MM, caller\'s timezone unless stated).',
            'gather_duration' => 'collect a duration; store as '.($params['unit'] ?? 'seconds').'.',
            'gather_masked' => isset($params['pattern'])
                ? 'collect a value matching mask `'.$params['pattern'].'` (9=digit, A=letter, *=either).'
                : 'collect a masked value.',
            'gather_choice' => isset($params['options']) && is_array($params['options']) && $params['options'] !== []
                ? 'pick one of: '.implode(', ', array_map(fn ($o) => '"'.$o.'"', $params['options'])).'.'
                : 'pick one of the configured options.',
            'gather_boolean' => 'collect yes/no; store as true or false.',
            'gather_address' => 'collect a structured address (street, city, state, postal'
                .(($params['require_postal'] ?? false) ? ' (required)' : '')
                .', country'
                .(isset($params['default_country']) && $params['default_country'] !== '' ? ' default '.$params['default_country'] : '')
                .').',
            default => '',
        };
    }

    /** @param array<string, mixed> $params */
    /**
     * Shared hint builder for the send_* messaging family. Emits a
     * one-liner the LLM can read: "send an email to `contact_email`,
     * subject `subject`, body `body` (wait 60s for reply)".
     *
     * @param  array<string, mixed>  $params
     */
    private function sendHint(string $kind, array $params, ?string $subjectKey, string $bodyKey): string
    {
        $recipient = $this->renderParam($params['recipient'] ?? null) ?: '(no recipient)';
        $parts = ['send a '.$kind.' to '.$recipient];
        if ($subjectKey !== null) {
            $subj = $this->renderParam($params[$subjectKey] ?? null);
            if ($subj !== '') {
                $parts[] = 'subject: '.$subj;
            }
        }
        $body = $this->renderParam($params[$bodyKey] ?? null);
        if ($body !== '') {
            $parts[] = 'body: '.$body;
        }
        $flags = [];
        if (! empty($params['wait_for_reply'])) {
            $timeout = isset($params['reply_timeout']) && (int) $params['reply_timeout'] > 0
                ? ' ('.(int) $params['reply_timeout'].'s)'
                : '';
            $flags[] = 'wait for reply'.$timeout;
        }
        if (! empty($params['require_ack'])) {
            $flags[] = 'require ACK';
        }
        if (! empty($params['reply_action'])) {
            $flags[] = 'on reply: '.$params['reply_action'];
        }
        if ($flags !== []) {
            $parts[] = '['.implode(', ', $flags).']';
        }

        return implode('; ', $parts).'.';
    }

    /**
     * Shared hint builder for the db_* primitives.
     *
     * @param  array<string, mixed>  $params
     */
    private function dbHint(string $verb, array $params): string
    {
        $conn = $params['connection_name'] ?? '';
        $target = $params['table_or_query'] ?? $params['table'] ?? '';
        $rendered = $this->renderParam($target);
        $connPart = $conn !== '' ? ' via connection "'.$conn.'"' : '';
        $targetPart = $rendered !== '' ? ' from `'.$rendered.'`' : '';

        return $verb.$connPart.$targetPart.'.';
    }

    /**
     * Hint for web_call — summarises method + path + endpoint.
     *
     * @param  array<string, mixed>  $params
     */
    private function webHint(array $params): string
    {
        $method = strtoupper((string) ($params['method'] ?? 'GET'));
        $path = $this->renderParam($params['path'] ?? null);
        $endpoint = $params['endpoint_name'] ?? '';
        $pathPart = $path !== '' ? ' '.$path : '';
        $endpointPart = $endpoint !== '' ? ' via endpoint "'.$endpoint.'"' : '';

        return 'make '.$method.$pathPart.$endpointPart.'; map response fields to slots.';
    }

    /** @param  array<string, mixed>  $params */
    private function summaryHint(array $params): string
    {
        $rendered = $this->renderParam($params['summary_template'] ?? null);
        $targetPart = ! empty($params['target_slot'])
            ? ' and stash in `'.$params['target_slot'].'`'
            : '';

        return $rendered !== ''
            ? 'save summary: '.$rendered.$targetPart.'.'
            : 'save summary (template not yet written).';
    }

    /** @param  array<string, mixed>  $params */
    private function parkCallHint(array $params): string
    {
        $type = $params['park_type'] ?? '';
        if ($type === '') {
            return 'park the call (park type not yet chosen).';
        }
        $target = $params['target'] ?? '';
        $targetPart = $target !== '' ? ': '.$target : '';

        return 'park the call ('.$type.$targetPart.'); resume flow when retrieved.';
    }

    /** @param  array<string, mixed>  $params */
    private function actionGroupHint(array $params): string
    {
        $id = (int) ($params['action_group_flow_id'] ?? 0);
        if ($id <= 0) {
            return 'invoke an action group (not yet chosen).';
        }
        $name = IntakeFlow::withoutGlobalScope('team')
            ->where('id', $id)
            ->value('name');

        return $name
            ? 'invoke action group "'.$name.'" — follow its steps, then resume here.'
            : 'invoke action group (referenced group missing or inactive).';
    }

    /** @param array<string, mixed> $params */
    private function numberHint(array $params): string
    {
        $bounds = [];
        if (isset($params['min']) && $params['min'] !== '') {
            $bounds[] = 'min '.$params['min'];
        }
        if (isset($params['max']) && $params['max'] !== '') {
            $bounds[] = 'max '.$params['max'];
        }
        if (isset($params['decimals']) && $params['decimals'] !== '' && (int) $params['decimals'] > 0) {
            $bounds[] = (int) $params['decimals'].' decimal places';
        }
        $tail = $bounds === [] ? '' : ' ('.implode(', ', $bounds).')';

        return 'collect a number'.$tail.'.';
    }

    /** @param array<string, mixed> $params */
    private function rangeHint(string $prefix, array $params, string $minKey, string $maxKey): string
    {
        $bounds = [];
        if (isset($params[$minKey]) && $params[$minKey] !== '') {
            $bounds[] = 'on or after '.$params[$minKey];
        }
        if (isset($params[$maxKey]) && $params[$maxKey] !== '') {
            $bounds[] = 'on or before '.$params[$maxKey];
        }

        return $prefix.($bounds === [] ? '' : ', '.implode(', ', $bounds)).'.';
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
     * @param  array<string, mixed>  $stepParams
     * @return array<string, mixed>
     */
    private function resolveGoal(IntakeGoal $goal, array $stepParams = []): array
    {
        $base = $goal->attributesToArray();

        $merged = $stepParams + $base;
        $merged['id'] = $goal->id;
        $merged['key'] = $goal->key;
        $merged['data_fields'] = $goal->data_fields ?? [];
        $merged['step_params'] = $stepParams;
        $merged['knowledge_store_ids'] = $stepParams['knowledge_store_ids']
            ?? $goal->knowledge_store_ids
            ?? [];

        return $merged;
    }

    /**
     * Build the function-schema set: set_field, advance_step,
     * transition_to_flow, plus any goal-bound tools discovered in
     * reachable flows.
     *
     * @param  Collection<int, array<string, mixed>>  $goals
     * @param  Collection<int, array<string, mixed>>  $compiledFlows
     * @return array<int, array<string, mixed>>
     */
    private function buildFunctionSchemas(Collection $goals, Collection $compiledFlows): array
    {
        $schemas = [];

        $schemas[] = [
            'name' => 'set_field',
            'description' => 'Record a collected slot value. Use the exact slot name from the Variables list at the top of the prompt.',
            'parameters' => [
                'type' => 'object',
                'required' => ['key', 'value'],
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'The slot name.'],
                    'value' => ['type' => 'string', 'description' => 'The captured value as text.'],
                ],
            ],
        ];

        $schemas[] = [
            'name' => 'advance_step',
            'description' => 'Advance from the current step in the active flow to the next step. Only call after the current step is complete.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'Optional one-line reason for advancing.'],
                ],
            ],
        ];

        $flowNames = $compiledFlows
            ->map(fn (array $cf) => $cf['flow']->name)
            ->values()
            ->all();

        $schemas[] = [
            'name' => 'transition_to_flow',
            'description' => 'Move to another flow as dictated by the current flow\'s "Next step" rules. Call this AFTER the current flow\'s steps are complete and one of its transitions should fire.',
            'parameters' => [
                'type' => 'object',
                'required' => ['flow_name'],
                'properties' => [
                    'flow_name' => [
                        'type' => 'string',
                        'enum' => $flowNames,
                        'description' => 'The exact name of the target flow.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Which transition rule you\'re following and why.',
                    ],
                ],
            ],
        ];

        // Goal-level tool bindings.
        $seen = [];
        foreach ($goals as $goal) {
            foreach ($goal['tools'] ?? [] as $tool) {
                $type = is_array($tool) ? ($tool['type'] ?? null) : null;
                if (! $type || isset($seen[$type])) {
                    continue;
                }
                $seen[$type] = true;
                $schema = $this->toolSchema($type);
                if ($schema) {
                    $schemas[] = $schema;
                }
            }
        }

        return $schemas;
    }

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
     * @param  Collection<int, array<string, mixed>>  $goals
     * @return array<int, array{id: int, name: string, description: ?string}>
     */
    private function collectAvailableStores(Collection $goals, ?int $teamId): array
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

    /**
     * New operator-view shape:
     *   entry_flow_id, slots[], flows[{ id, name, description, is_entry,
     *     steps[], transitions[] }]
     *
     * `CompiledFlowViewer` reads this and renders a per-flow checklist
     * with a selector at the top for the currently-active flow.
     *
     * @param  Collection<int, array<string, mixed>>  $compiledFlows
     * @param  Collection<int, array<string, mixed>>  $slots
     * @return array<string, mixed>
     */
    private function buildOperatorView(IntakeFlow $entry, Collection $compiledFlows, Collection $slots): array
    {
        return [
            'entry_flow_id' => $entry->id,
            'slots' => $slots->all(),
            'flows' => $compiledFlows->map(function (array $cf) use ($entry) {
                /** @var IntakeFlow $flow */
                $flow = $cf['flow'];
                /** @var Collection<int, array<string, mixed>> $goals */
                $goals = $cf['goals'];
                /** @var Collection<int, array<string, mixed>> $transitions */
                $transitions = $cf['transitions'];

                return [
                    'id' => $flow->id,
                    'name' => $flow->name,
                    'description' => $flow->description,
                    'is_entry' => $flow->id === $entry->id,
                    'steps' => $goals->map(fn (array $g) => [
                        'key' => $g['key'],
                        'name' => $g['name'],
                        'description' => $g['description'],
                        'icon' => $g['icon'],
                        'talking_points' => $g['talking_points'],
                        'data_fields' => $g['data_fields'],
                        'completion' => $g['completion'],
                        'step_params' => $g['step_params'] ?? [],
                    ])->all(),
                    'transitions' => $transitions->map(fn (array $t) => [
                        'to_flow_id' => $t['to_flow_id'],
                        'to_flow_name' => $t['to_flow_name'],
                        'description' => $t['description'],
                        'priority' => $t['priority'],
                        'condition_human' => $t['condition_human'],
                        'is_fallback' => $t['is_fallback'],
                        'ends_call' => $t['ends_call'],
                    ])->all(),
                ];
            })->all(),
        ];
    }
}
