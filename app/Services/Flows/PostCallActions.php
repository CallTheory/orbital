<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\AgentPersona;
use App\Models\CallSessionState;
use App\Models\ClientWebEndpoint;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Services\Messages\PartialMessagePolicy;
use App\Services\Messages\SessionMessageWriter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Runs a call's action steps (Send Email, Web Call) once the call is
 * over. These aren't the AI's to decide: the AI takes the message, and
 * Orbital does what the flow says with it afterwards.
 *
 * The AI doesn't report which flows it went through, so the path is
 * worked out from the graph: start at the call's entry flow, follow
 * the first transition whose condition matches what was captured, and
 * collect the action steps on the way (including those in invoked
 * action groups). The walk stops at a step the call didn't get past:
 * intake / take-a-message steps without a complete message, or a
 * transfer that never happened.
 */
class PostCallActions
{
    /** Primitives Orbital runs after the call, not the AI. */
    public const ACTION_KEYS = ['send_email', 'web_call'];

    private const MAX_FLOWS = 25;

    private const DEFAULT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly SessionMessageWriter $messages,
        private readonly PartialMessagePolicy $policy,
        private readonly TemplateEvaluator $templates,
        private readonly ConditionEvaluator $conditions,
    ) {}

    /**
     * Run the session's actions once. Returns what happened, or null
     * when they already ran (or the session isn't over yet).
     *
     * @return list<array<string, mixed>>|null
     */
    public function run(CallSessionState $state): ?array
    {
        if (! $state->team_id || ! $state->hasEnded()) {
            return null;
        }

        // Both the worker's end call and LiveKit's room_finished
        // webhook get here; only the first may send anything.
        $claimed = CallSessionState::whereKey($state->id)
            ->whereNull('post_call_actions_at')
            ->update(['post_call_actions_at' => now()]);
        if ($claimed === 0) {
            return null;
        }

        $context = $this->context($state);
        $results = array_map(
            fn (IntakeFlowStep $step) => $this->execute($step, $state, $context),
            $this->actionsFor($state, $context),
        );

        $state->forceFill(['post_call_actions' => $results])->save();

        return $results;
    }

    /**
     * The action steps on the path the call took, in order.
     *
     * @param  array<string, mixed>  $context
     * @return list<IntakeFlowStep>
     */
    public function actionsFor(CallSessionState $state, array $context): array
    {
        $actions = [];
        $visited = [];
        $flow = $this->entryFlow($state);

        while ($flow && ! isset($visited[$flow->id]) && count($visited) < self::MAX_FLOWS) {
            $visited[$flow->id] = true;
            $flow->loadMissing(['steps.intakeGoal', 'transitionsOut']);

            foreach ($flow->steps as $step) {
                $key = $step->intakeGoal?->key;
                if (in_array($key, self::ACTION_KEYS, true)) {
                    $actions[] = $step;
                } elseif ($key === 'call_action_group') {
                    array_push($actions, ...$this->groupActions((int) ($step->step_params['action_group_flow_id'] ?? 0)));
                } elseif (! $this->callGotPast($key, $state)) {
                    return $actions;
                }
            }

            $next = $flow->transitionsOut
                ->sortBy('priority')
                ->first(fn ($t) => $this->conditions->evaluate($t->condition, $context));
            $flow = $next?->to_flow_id
                ? IntakeFlow::withoutGlobalScope('team')->find($next->to_flow_id)
                : null;
        }

        return $actions;
    }

    /**
     * The flow a call to this persona starts in: the persona's AI
     * extension's flow, else the persona's default — as the compiler
     * picks it, minus routing rules, which the session doesn't record.
     */
    private function entryFlow(CallSessionState $state): ?IntakeFlow
    {
        if (! $state->agent_persona_id) {
            return null;
        }

        $flowId = Extension::withoutGlobalScopes()
            ->where('type', 'ai_agent')
            ->where('assignable_type', AgentPersona::class)
            ->where('assignable_id', $state->agent_persona_id)
            ->whereNotNull('intake_flow_id')
            ->value('intake_flow_id')
            ?? AgentPersona::withoutGlobalScopes()->whereKey($state->agent_persona_id)->value('default_flow_id');

        return $flowId ? IntakeFlow::withoutGlobalScope('team')->find($flowId) : null;
    }

    /**
     * @param  array<int, true>  $seen
     * @return list<IntakeFlowStep>
     */
    private function groupActions(int $flowId, array $seen = []): array
    {
        $group = $flowId > 0 && ! isset($seen[$flowId])
            ? IntakeFlow::withoutGlobalScope('team')->with('steps.intakeGoal')->find($flowId)
            : null;
        if (! $group) {
            return [];
        }
        $seen[$flowId] = true;

        $actions = [];
        foreach ($group->steps as $step) {
            $key = $step->intakeGoal?->key;
            if (in_array($key, self::ACTION_KEYS, true)) {
                $actions[] = $step;
            } elseif ($key === 'call_action_group') {
                array_push($actions, ...$this->groupActions((int) ($step->step_params['action_group_flow_id'] ?? 0), $seen));
            }
        }

        return $actions;
    }

    private function callGotPast(?string $key, CallSessionState $state): bool
    {
        return match ($key) {
            'gather_intake_fields', 'save_message' => $this->policy->isComplete($this->messages->normalise($state)),
            'transfer_call' => $state->handed_off_at !== null,
            default => true,
        };
    }

    /** @return array<string, mixed> */
    private function context(CallSessionState $state): array
    {
        $captured = array_filter($this->messages->normalise($state), fn ($v) => $v !== null);

        return [
            'slots' => ($state->fields ?? []) + $captured,
            'context' => [
                'now' => new DateTimeImmutable('now', new DateTimeZone(config('app.timezone') ?? 'UTC')),
                'call' => [
                    'caller_number' => $state->caller_number,
                    'called_number' => $state->called_number,
                    'session_key' => $state->session_key,
                    'end_reason' => $state->end_reason,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function execute(IntakeFlowStep $step, CallSessionState $state, array $context): array
    {
        $key = $step->intakeGoal->key;
        $params = $step->step_params ?? [];
        $result = ['action' => $key, 'flow_id' => $step->flow_id, 'step_id' => $step->id];

        try {
            $detail = match ($key) {
                'send_email' => $this->sendEmail($params, $context),
                'web_call' => $this->webCall($params, $state, $context),
            };
            $result += ['ok' => true, 'detail' => $detail];
            Log::info('post-call action ran', $result + ['session_key' => $state->session_key]);
        } catch (Throwable $e) {
            $result += ['ok' => false, 'detail' => $e->getMessage()];
            Log::warning('post-call action failed', $result + ['session_key' => $state->session_key]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function sendEmail(array $params, array $context): string
    {
        $recipients = array_values(array_filter(
            preg_split('/[\s,;]+/', $this->templates->render((string) ($params['recipient'] ?? ''), $context)) ?: [],
        ));
        $invalid = array_filter($recipients, fn ($r) => filter_var($r, FILTER_VALIDATE_EMAIL) === false);
        if ($recipients === [] || $invalid !== []) {
            throw new RuntimeException('No valid recipient: '.($invalid ? implode(', ', $invalid) : 'none given'));
        }

        // A header can't span lines.
        $subject = trim(preg_replace('/\s+/', ' ', $this->templates->render((string) ($params['subject'] ?? ''), $context)));
        $body = $this->templates->render((string) ($params['body'] ?? ''), $context);

        Mail::raw($body, fn (Message $m) => $m->to($recipients)->subject($subject));

        return 'sent to '.implode(', ', $recipients);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function webCall(array $params, CallSessionState $state, array $context): string
    {
        $endpoint = ClientWebEndpoint::withoutGlobalScopes()
            ->where('team_id', $state->team_id)
            ->where('name', (string) ($params['endpoint_name'] ?? ''))
            ->where('is_active', true)
            ->first();

        $path = trim($this->templates->render((string) ($params['path'] ?? ''), $context));
        $url = match (true) {
            (bool) preg_match('#^https?://#i', $path) => $path,
            $endpoint !== null => rtrim($endpoint->base_url, '/').'/'.ltrim($path, '/'),
            default => throw new RuntimeException('No active web endpoint named "'.($params['endpoint_name'] ?? '').'", and the path is not a full URL'),
        };
        if (! preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Only http(s) URLs can be called');
        }

        $method = strtoupper((string) ($params['method'] ?? 'POST'));
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new RuntimeException("Unsupported method {$method}");
        }

        $headers = array_merge($endpoint?->default_headers ?? [], $this->jsonParam($params['headers'] ?? null, $context));
        $request = $this->authenticate(
            Http::timeout($endpoint?->timeout_seconds ?: self::DEFAULT_TIMEOUT_SECONDS)->withHeaders($headers),
            $endpoint,
        );

        if ($query = $this->jsonParam($params['query_params'] ?? null, $context)) {
            $request = $request->withQueryParameters($query);
        }

        $template = (string) ($params['body_template'] ?? '');
        if (trim($template) !== '') {
            // In a JSON body, captured values are escaped so a quote in
            // what the caller said can't break the document.
            $isJson = in_array(ltrim($template)[0], ['{', '['], true);
            $body = $this->templates->render($template, $isJson ? $this->jsonEscaped($context) : $context);
            $request = $request->withBody($body, $isJson ? 'application/json' : 'text/plain');
        }

        $status = $request->send($method, $url)->status();

        $expected = array_map('intval', array_filter(array_map('trim', explode(',', (string) ($params['expected_status_codes'] ?? '')))));
        $ok = $expected !== [] ? in_array($status, $expected, true) : $status >= 200 && $status < 300;
        if (! $ok) {
            throw new RuntimeException("{$method} {$url} returned HTTP {$status}");
        }

        return "{$method} {$url} returned HTTP {$status}";
    }

    private function authenticate(PendingRequest $request, ?ClientWebEndpoint $endpoint): PendingRequest
    {
        $credentials = $endpoint?->credentials ?? [];

        return match ($endpoint?->auth_type ?? ClientWebEndpoint::AUTH_NONE) {
            ClientWebEndpoint::AUTH_BEARER => $request->withToken((string) ($credentials['token'] ?? '')),
            ClientWebEndpoint::AUTH_BASIC => $request->withBasicAuth((string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? '')),
            ClientWebEndpoint::AUTH_API_KEY => $request->withHeaders([
                (string) ($credentials['header'] ?? 'X-API-Key') => (string) ($credentials['key'] ?? ''),
            ]),
            ClientWebEndpoint::AUTH_NONE => $request,
            default => throw new RuntimeException("Endpoint auth type {$endpoint->auth_type} isn't supported for post-call web calls"),
        };
    }

    /**
     * A JSON-object step param (headers, query params) with each value
     * rendered as a template.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private function jsonParam(mixed $raw, array $context): array
    {
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_map(fn ($v) => $this->templates->render((string) $v, $context), $decoded);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function jsonEscaped(array $context): array
    {
        $context['slots'] = array_map(
            fn ($v) => is_string($v) ? substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : $v,
            $context['slots'],
        );

        return $context;
    }
}
