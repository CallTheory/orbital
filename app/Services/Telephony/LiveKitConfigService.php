<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\Extension;
use Firebase\JWT\JWT;
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
     * Generate a LiveKit API JWT for authenticated Twirp calls.
     */
    public function generateToken(): string
    {
        $payload = [
            'iss' => $this->apiKey,
            'exp' => time() + 60,
            'nbf' => time(),
            'sub' => '',
            'video' => [
                'roomCreate' => true,
                'roomList' => true,
                'roomAdmin' => true,
            ],
            'sip' => [
                'admin' => true,
                'call' => true,
            ],
        ];

        return JWT::encode($payload, $this->apiSecret, 'HS256');
    }

    /**
     * Create an inbound SIP trunk in LiveKit so the SIP bridge
     * accepts calls from Asterisk.
     */
    public function createInboundTrunk(): array
    {
        $response = Http::withToken($this->generateToken(), 'Bearer')
            ->post("{$this->apiUrl}/twirp/livekit.SIP/CreateSIPInboundTrunk", [
                'trunk' => [
                    'name' => 'Asterisk',
                    'numbers' => [],
                    'allowed_addresses' => ['0.0.0.0/0'],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Failed to create SIP inbound trunk: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * List existing SIP inbound trunks.
     */
    public function listInboundTrunks(): array
    {
        $response = Http::withToken($this->generateToken(), 'Bearer')
            ->post("{$this->apiUrl}/twirp/livekit.SIP/ListSIPInboundTrunk", []);

        return $response->successful() ? ($response->json('items') ?? []) : [];
    }

    /**
     * List existing SIP dispatch rules.
     */
    public function listDispatchRules(): array
    {
        $response = Http::withToken($this->generateToken(), 'Bearer')
            ->post("{$this->apiUrl}/twirp/livekit.SIP/ListSIPDispatchRule", []);

        return $response->successful() ? ($response->json('items') ?? []) : [];
    }

    /**
     * Create a SIP dispatch rule in LiveKit.
     */
    public function createDispatchRule(array $rule): array
    {
        $response = Http::withToken($this->generateToken(), 'Bearer')
            ->post("{$this->apiUrl}/twirp/livekit.SIP/CreateSIPDispatchRule", $rule);

        if (! $response->successful()) {
            throw new \RuntimeException("Failed to create SIP dispatch rule: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Delete a SIP dispatch rule by ID.
     */
    public function deleteDispatchRule(string $ruleId): void
    {
        Http::withToken($this->generateToken(), 'Bearer')
            ->post("{$this->apiUrl}/twirp/livekit.SIP/DeleteSIPDispatchRule", [
                'sipDispatchRuleId' => $ruleId,
            ]);
    }

    /**
     * Sync all AI agent extensions as LiveKit SIP dispatch rules.
     * Deletes existing rules and recreates from current DB state.
     */
    public function syncDispatchRules(): array
    {
        // Delete existing rules.
        $existing = $this->listDispatchRules();
        foreach ($existing as $rule) {
            $id = $rule['sipDispatchRuleId'] ?? null;
            if ($id) {
                $this->deleteDispatchRule($id);
            }
        }

        // Ensure at least one inbound trunk exists.
        $trunks = $this->listInboundTrunks();
        if (empty($trunks)) {
            $this->createInboundTrunk();
        }

        // Create dispatch rules for each AI extension.
        $rules = $this->buildDispatchRules();
        $created = [];
        foreach ($rules as $rule) {
            $created[] = $this->createDispatchRule($rule);
        }

        return $created;
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
