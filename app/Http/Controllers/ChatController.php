<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentPersona;
use App\Models\ChatSession;
use App\Models\Team;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public chat entry point. The URL carries the client slug and a
 * persona id/slug so a customer can land directly on a chat session
 * without any authentication. On first visit we create a ChatSession
 * row keyed by a random public token stored in the session.
 *
 * This is intentionally minimal — the real work lives in the Livewire
 * component (ChatConsole) and the chat API endpoint. The controller
 * just resolves the client + persona and hands off.
 */
class ChatController extends Controller
{
    public function show(Request $request, string $client, int $persona)
    {
        $team = Team::where('id', $client)
            ->orWhere('name', $client)
            ->firstOr(fn () => throw new NotFoundHttpException('Unknown client.'));

        $personaModel = AgentPersona::withoutGlobalScope('team')
            ->where('team_id', $team->id)
            ->where('id', $persona)
            ->where('is_active', true)
            ->firstOr(fn () => throw new NotFoundHttpException('Unknown persona.'));

        // Hydrate or create the session row keyed to the browser session
        // so refreshes keep the transcript.
        $sessionKey = "chat_session:{$team->id}:{$personaModel->id}";
        $publicToken = $request->session()->get($sessionKey);
        $chatSession = $publicToken
            ? ChatSession::where('public_token', $publicToken)->first()
            : null;

        if (! $chatSession) {
            $chatSession = ChatSession::create([
                'team_id' => $team->id,
                'agent_persona_id' => $personaModel->id,
                'messages' => [],
                'fields' => [],
                'last_activity_at' => now(),
            ]);
            $request->session()->put($sessionKey, $chatSession->public_token);
        }

        return view('chat.show', [
            'team' => $team,
            'persona' => $personaModel,
            'chatSession' => $chatSession,
        ]);
    }
}
