<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\Extension;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LiveKitConfigService
{
    protected string $apiUrl;
    protected string $apiKey;
    protected string $apiSecret;

    public function __construct()
    {
        $mode = config('telephony.livekit.mode');
        $config = config("telephony.livekit.{$mode}",
            config('telephony.livekit.local')
        );

        $this->apiUrl = $config['url'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';
    }

    /**
     * Get the active LiveKit connection parameters.
     */
    public function getConnectionParams(): array
    {
        $mode = config('telephony.livekit.mode');

        return config("telephony.livekit.{$mode}",
            config('telephony.livekit.local')
        );
    }

    /**
     * Build SIP dispatch rules for AI agent extensions.
     *
     * @return array<int, array{rule: array, agentName: string}>
     */
    public function buildDispatchRules(?int $teamId = null): array
    {
        $aiExtensions = Extension::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('type', 'ai_agent')
            ->where('is_active', true)
            ->with('assignable')
            ->get();

        $rules = [];
        foreach ($aiExtensions as $ext) {
            $rules[] = [
                'rule' => [
                    'dispatchRuleCallee' => [
                        'roomPrefix' => "agent-{$ext->number}_",
                        'pin' => '',
                    ],
                ],
                'trunkIds' => [],
                'hidePhoneNumber' => false,
            ];
        }

        return $rules;
    }

    /**
     * Check if the LiveKit server is reachable.
     */
    public function healthCheck(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->apiUrl);

            return $response->successful() || $response->status() === 404;
        } catch (\Throwable $e) {
            Log::warning('LiveKit health check failed: '.$e->getMessage());

            return false;
        }
    }
}
