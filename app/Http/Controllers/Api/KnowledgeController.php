<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeStore;
use App\Services\Knowledge\RetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Knowledge retrieval API. Consumed by:
 *   - The Python agent worker when a flow step calls the `search_knowledge`
 *     function tool.
 *   - The operator softphone when the operator clicks "check knowledge"
 *     on a FAQ-style step.
 *
 * Two auth modes:
 *   - `Authorization: Bearer <worker_token>` — the agent worker shared
 *     secret from services.agent_worker.token. Worker searches on behalf
 *     of a specific client identified by store_ids + team_id check.
 *   - Sanctum session — the operator UI, which inherits the authed
 *     user's team context.
 */
class KnowledgeController extends Controller
{
    public function __construct(
        private readonly RetrievalService $retrieval,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_ids' => 'required|array|min:1',
            'store_ids.*' => 'integer',
            'query' => 'required|string|min:2|max:4000',
            'top_k' => 'nullable|integer|min:1|max:50',
        ]);

        $teamId = $this->resolveTeamId($request, $data['store_ids']);
        if ($teamId === null) {
            abort(401, 'Unauthenticated.');
        }

        $results = $this->retrieval->searchForTeam(
            teamId: $teamId,
            storeIds: array_map('intval', $data['store_ids']),
            query: $data['query'],
            topK: (int) ($data['top_k'] ?? 5),
        );

        return response()->json(['results' => $results]);
    }

    /**
     * Figure out which team the request is acting on behalf of.
     *
     * If the caller is the agent worker (bearer token = services.agent_worker.token),
     * we derive the team from the first requested store — and then the
     * retrieval service re-validates every store_id against that team,
     * so a worker can't fan out across clients in one request.
     *
     * If the caller is a session user, we use their current_team_id.
     */
    private function resolveTeamId(Request $request, array $storeIds): ?int
    {
        $workerToken = (string) config('services.agent_worker.token');
        if ($workerToken !== '' && hash_equals($workerToken, (string) $request->bearerToken())) {
            $firstStore = KnowledgeStore::withoutGlobalScope('team')->find($storeIds[0] ?? 0);
            return $firstStore?->team_id;
        }

        if ($user = $request->user()) {
            return (int) ($user->current_team_id ?? $user->currentTeam?->id);
        }

        return null;
    }
}
