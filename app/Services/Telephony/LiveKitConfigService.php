<?php

declare(strict_types=1);

namespace App\Services\Telephony;

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

        // The API is HTTP (Twirp) on the same port as the WebSocket the
        // agents use; LIVEKIT_URL is often given as ws:// or wss://.
        $this->apiUrl = preg_replace('#^ws(s?)://#', 'http$1://', (string) ($config['url'] ?? ''));
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
     * The SIP dispatch rule for AI agent calls.
     *
     * Every call Asterisk sends to LiveKit is an AI call
     * (Dial(PJSIP/{ext}@livekit)), so one rule covers every extension:
     * the room is named after the dialled extension — agent-{ext}_{random},
     * which the worker parses — and the named agent worker is dispatched
     * into it. (One rule per extension would overlap: rules with no trunk
     * or number filter all match every call.) It doesn't depend on which
     * extensions exist, so a new AI extension needs no re-sync.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildDispatchRules(): array
    {
        return [[
            'name' => 'orbital-ai-agents',
            'rule' => [
                'dispatchRuleCallee' => [
                    'roomPrefix' => 'agent-',
                    'pin' => '',
                    'randomize' => true,
                ],
            ],
            'trunkIds' => [],
            'hidePhoneNumber' => false,
            'roomConfig' => [
                'agents' => [
                    ['agentName' => (string) config('telephony.livekit.agent_name', 'orbital-agent')],
                ],
            ],
        ]];
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
                    // X-Orbital-DID (the number the caller dialled) reaches
                    // the worker as a participant attribute.
                    'include_headers' => 'SIP_X_HEADERS',
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
