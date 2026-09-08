<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentPersona;
use App\Models\CallSessionState;
use App\Models\Extension;
use App\Services\Messages\PartialMessagePolicy;
use App\Services\Messages\SessionMessageWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dual-audience controller for the shared call session state.
 *
 * The agent worker calls `captureField` and `advance` with its bearer
 * token. The operator Livewire UI calls `show` through the Sanctum
 * session to hydrate its form state.
 *
 * Client isolation lives in two places:
 *   1. The worker only knows a session_key + the fields it has collected;
 *      it can't read another client's session because the path is scoped
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
     * The call is over. The worker calls this when the caller hangs up
     * or the room closes.
     *
     * This is what makes partial-message retention possible: until the
     * session is known to have ENDED, an incomplete capture is just a
     * conversation still in progress. Without an explicit end signal the
     * only options are to write partials prematurely (creating
     * duplicates when the caller then finishes) or never at all.
     *
     * Belt and braces: the LiveKit room_finished webhook calls the same
     * path, so a worker that dies without cleaning up still gets its
     * session finalised. markEnded() is idempotent, so whichever arrives
     * first wins and the second is a no-op.
     */
    public function end(Request $request, string $sessionKey): JsonResponse
    {
        if (! $this->isWorker($request)) {
            abort(401);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:64',
            'extension' => 'nullable|string|max:32',
        ]);

        $state = $this->upsert($sessionKey, $data['extension'] ?? null);
        $state->markEnded($data['reason'] ?? PartialMessagePolicy::REASON_CALLER_HUNG_UP);

        $this->maybePersistMessage($state->fresh());

        return response()->json(['data' => $this->serialize($state->fresh())]);
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
     * Delegates to the session message writer. See
     * App\Services\Messages\SessionMessageWriter for when a complete
     * vs. partial message gets written, and why.
     */
    protected function maybePersistMessage(CallSessionState $state): void
    {
        app(SessionMessageWriter::class)->write($state);
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
            'ended_at' => $state->ended_at?->toIso8601String(),
            'end_reason' => $state->end_reason,
        ];
    }
}
