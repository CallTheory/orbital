<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentPersona;
use App\Models\Extension;
use App\Services\Flows\AgentFlowCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentPersonaController extends Controller
{
    public function show(AgentPersona $agentPersona): JsonResponse
    {
        return response()->json([
            'data' => $agentPersona->only([
                'id', 'name', 'role', 'description',
                'system_prompt', 'greeting', 'outbound_greeting',
                'personality', 'voice_id', 'voice_config',
                'llm_provider', 'llm_model', 'stt_provider', 'tts_provider',
                'tools_config',
            ]),
        ]);
    }

    public function byExtension(Request $request, string $extension, AgentFlowCompiler $compiler): JsonResponse
    {
        $ext = Extension::withoutGlobalScopes()
            ->where('number', $extension)
            ->where('type', 'ai_agent')
            ->where('is_active', true)
            ->first();

        if (! $ext || ! $ext->assignable || ! ($ext->assignable instanceof AgentPersona)) {
            return response()->json(['error' => 'No agent persona found for this extension'], 404);
        }

        $persona = $ext->assignable;

        // Compile the bound flow (if any) at request time. The resolver
        // walks routing_rule → extension → persona.default and always
        // returns a CompiledFlow — even when no flow is bound, the
        // `llm_instructions` field still carries the persona baseline
        // so the worker has something to work from.
        $compiled = $compiler->compile(persona: $persona, extension: $ext);

        return response()->json([
            'data' => $persona->only([
                'id', 'name', 'role', 'description',
                'system_prompt', 'greeting', 'outbound_greeting',
                'personality', 'voice_id', 'voice_config',
                'llm_provider', 'llm_model', 'stt_provider', 'tts_provider',
                'tools_config',
            ]),
            'compiled_flow' => $compiled->toArray(),
            'provider_keys' => $request->attributes->get('agent_worker') ? $this->providerKeys() : [],
        ]);
    }

    /**
     * AI provider keys as saved in Platform Settings (or the environment),
     * so the worker uses what the admin entered rather than only its own
     * env. Empty keys are left out and the worker falls back to its env.
     *
     * @return array<string, string>
     */
    protected function providerKeys(): array
    {
        $keys = [];
        foreach (['anthropic', 'openai', 'openrouter', 'elevenlabs', 'deepgram'] as $provider) {
            $value = (string) config("services.{$provider}.api_key");
            if ($value !== '') {
                $keys[$provider] = $value;
            }
        }

        return $keys;
    }
}
