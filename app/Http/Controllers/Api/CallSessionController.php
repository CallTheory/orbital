<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentPersona;
use App\Models\CallSessionState;
use App\Models\Extension;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dual-audience controller for the shared call session state.
 *
 * The agent worker calls `captureField` and `advance` with its bearer
 * token. The operator Livewire UI calls `show` through the Sanctum
 * session to hydrate its form state.
 *
 * Tenant isolation lives in two places:
 *   1. The worker only knows a session_key + the fields it has collected;
 *      it can't read another tenant's session because the path is scoped
 *      to a single key it controls.
 *   2. Operator reads go through the session user's current team and we
 *      cross-check CallSessionState.team_id before returning data.
 */
class CallSessionController extends Controller
{
    public function show(Request $request, string $sessionKey): JsonResponse
    {
        $state = CallSessionState::where('session_key', $sessionKey)->first();

        if (! $state) {
            return response()->json(['data' => null]);
        }

        // Operator authz: if a session user is asking, they must be on the
        // owning team (or super-admin).
        $user = $request->user();
        if ($user && ! $this->isWorker($request)) {
            $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
            if (! $isSuperAdmin && (int) $user->current_team_id !== (int) $state->team_id) {
                abort(403, 'Session belongs to a different team.');
            }
        }

        return response()->json(['data' => $this->serialize($state)]);
    }

    /**
     * Upsert the session record and capture a single field. This is the
     * agent worker's `set_field` implementation.
     */
    public function captureField(Request $request, string $sessionKey): JsonResponse
    {
        if (! $this->isWorker($request)) {
            abort(401);
        }

        $data = $request->validate([
            'key' => 'required|string|max:64',
            'value' => 'nullable|string|max:4000',
            'extension' => 'nullable|string|max:32',
        ]);

        $state = $this->upsert($sessionKey, $data['extension'] ?? null);
        $state->captureField($data['key'], $data['value']);

        // Auto-persist message if all required fields are now present.
        $this->maybePersistMessage($state->fresh());

        return response()->json(['data' => $this->serialize($state->fresh())]);
    }

    /**
     * Worker tells us it's advancing from the current step to the next.
     */
    public function advance(Request $request, string $sessionKey): JsonResponse
    {
        if (! $this->isWorker($request)) {
            abort(401);
        }

        $request->validate([
            'extension' => 'nullable|string|max:32',
        ]);

        $state = $this->upsert($sessionKey, $request->input('extension'));
        $state->active_step = ($state->active_step ?? 0) + 1;
        $state->save();

        // Check if the session has captured message fields and
        // persist as a Message record if all required fields are present.
        $this->maybePersistMessage($state);

        return response()->json(['data' => $this->serialize($state)]);
    }

    /**
     * Create the row on first touch, backfilling team_id + agent_persona_id
     * from the extension the worker hands us. Subsequent writes are
     * no-op on those columns.
     */
    protected function upsert(string $sessionKey, ?string $extensionNumber): CallSessionState
    {
        return CallSessionState::firstOrCreate(
            ['session_key' => $sessionKey],
            $this->resolveContext($extensionNumber),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveContext(?string $extensionNumber): array
    {
        if (! $extensionNumber) {
            return [];
        }

        $ext = Extension::withoutGlobalScopes()
            ->where('number', $extensionNumber)
            ->where('type', 'ai_agent')
            ->first();

        if (! $ext) {
            return [];
        }

        $context = ['team_id' => $ext->team_id];
        if ($ext->assignable instanceof AgentPersona) {
            $context['agent_persona_id'] = $ext->assignable->id;
        }

        return $context;
    }

    /**
     * If the session has caller_name, caller_phone, and reason captured,
     * persist them as a Message record linked to the tenant. Idempotent —
     * checks for an existing message with this session key in metadata
     * to prevent duplicates on retry.
     */
    protected function maybePersistMessage(CallSessionState $state): void
    {
        $fields = $state->fields ?? [];

        // The LLM may use different key names than our intake goal
        // defines. Try the canonical keys first, then common variants.
        $name = $fields['caller_name']
            ?? $fields['name']
            ?? $fields['recipient']
            ?? $fields['customer_name']
            ?? $fields['caller']
            ?? null;

        $phone = $fields['caller_phone']
            ?? $fields['phone']
            ?? $fields['callback_number']
            ?? $fields['phone_number']
            ?? $fields['number']
            ?? null;

        $reason = $fields['reason']
            ?? $fields['message']
            ?? $fields['reason_for_call']
            ?? $fields['notes']
            ?? $fields['details']
            ?? null;

        if (! $name || ! $reason || ! $state->team_id) {
            return;
        }

        // Idempotent: don't create duplicate messages for the same session
        $exists = Message::where('team_id', $state->team_id)
            ->whereJsonContains('notes', $state->session_key)
            ->exists();

        if ($exists) {
            return;
        }

        Message::create([
            'team_id' => $state->team_id,
            'agent_persona_id' => $state->agent_persona_id,
            'caller_name' => $name,
            'caller_phone' => $phone,
            'reason' => $reason,
            'status' => Message::STATUS_NEW,
            'urgency' => Message::URGENCY_NORMAL,
            'notes' => "Auto-captured from AI call session: {$state->session_key}",
        ]);
    }

    protected function isWorker(Request $request): bool
    {
        $token = (string) config('services.agent_worker.token');

        return $token !== '' && hash_equals($token, (string) $request->bearerToken());
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(CallSessionState $state): array
    {
        return [
            'session_key' => $state->session_key,
            'team_id' => $state->team_id,
            'agent_persona_id' => $state->agent_persona_id,
            'fields' => $state->fields ?? [],
            'active_step' => $state->active_step,
            'operator_owned' => $state->operator_owned,
            'last_field_at' => $state->last_field_at?->toIso8601String(),
        ];
    }
}
