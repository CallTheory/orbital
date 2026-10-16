<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\HandoffResource;
use App\Models\CallSessionState;
use App\Models\Team;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a client's outside operator fetch what the AI collected before
 * transferring the caller. Team comes from the API key, never the query.
 */
final class HandoffController extends Controller
{
    public function lookup(Request $request): HandoffResource|JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'within' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $phone = PhoneNumber::toE164($data['phone']);
        if ($phone === null) {
            return response()->json(['message' => 'No handed-off call found for that number.'], 404);
        }

        /** @var Team $team */
        $team = $request->attributes->get('team');
        $within = (int) ($data['within'] ?? 30);

        $session = CallSessionState::query()
            ->where('team_id', $team->id)
            ->where('caller_number', $phone)
            ->whereNotNull('handed_off_at')
            ->where('handed_off_at', '>=', now()->subMinutes($within))
            ->orderByDesc('handed_off_at')
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            return response()->json(['message' => 'No handed-off call found for that number.'], 404);
        }

        return new HandoffResource($session);
    }

    public function show(Request $request, int $id): HandoffResource|JsonResponse
    {
        /** @var Team $team */
        $team = $request->attributes->get('team');

        $session = CallSessionState::query()
            ->where('team_id', $team->id)
            ->find($id);

        if ($session === null) {
            return response()->json(['message' => 'Handoff not found.'], 404);
        }

        return new HandoffResource($session);
    }
}
