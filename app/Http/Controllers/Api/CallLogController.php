<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'unique_id' => 'nullable|string',
            'extension_id' => 'nullable|integer|exists:extensions,id',
            'agent_persona_id' => 'nullable|integer|exists:agent_personas,id',
            'caller_id_name' => 'nullable|string',
            'caller_id_num' => 'nullable|string',
            'from_number' => 'nullable|string',
            'to_number' => 'nullable|string',
            'direction' => 'required|in:inbound,outbound,internal',
            'status' => 'required|string',
            'disposition' => 'nullable|string',
            'duration_seconds' => 'nullable|integer',
            'started_at' => 'nullable|date',
            'answered_at' => 'nullable|date',
            'ended_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ]);

        $callLog = CallLog::create($validated);

        return response()->json(['data' => $callLog], 201);
    }

    public function updateTranscript(CallLog $callLog, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transcript' => 'required|string',
        ]);

        $callLog->update($validated);

        return response()->json(['data' => $callLog]);
    }
}
