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
     *
     * Throws when LiveKit can't answer: treating a failed call as "none"
     * is what made every sync create another trunk.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInboundTrunks(): array
    {
        return $this->twirp('ListSIPInboundTrunk')['items'] ?? [];
    }

    /**
     * List existing SIP dispatch rules.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDispatchRules(): array
    {
        return $this->twirp('ListSIPDispatchRule')['items'] ?? [];
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
        $this->twirp('DeleteSIPDispatchRule', ['sip_dispatch_rule_id' => $ruleId]);
    }

    /**
     * Delete a SIP trunk (inbound or outbound) by ID.
     */
    public function deleteTrunk(string $trunkId): void
    {
        $this->twirp('DeleteSIPTrunk', ['sip_trunk_id' => $trunkId]);
    }

    /**
     * Sync all AI agent extensions as LiveKit SIP dispatch rules.
     * Deletes existing rules and recreates from current DB state, and
     * leaves exactly one inbound trunk for Asterisk.
     */
    public function syncDispatchRules(): array
    {
        // Delete existing rules.
        foreach ($this->listDispatchRules() as $rule) {
            $id = $rule['sip_dispatch_rule_id'] ?? $rule['sipDispatchRuleId'] ?? null;
            if ($id) {
                $this->deleteDispatchRule($id);
            }
        }

        // Exactly one inbound trunk for Asterisk. Earlier syncs could add
        // a duplicate each run; with two matching trunks LiveKit can't
        // tell which one a call belongs to.
        $asterisk = array_values(array_filter(
            $this->listInboundTrunks(),
            fn (array $trunk) => ($trunk['name'] ?? null) === 'Asterisk',
        ));
        if ($asterisk === []) {
            $this->createInboundTrunk();
        }
        foreach (array_slice($asterisk, 1) as $duplicate) {
            $id = $duplicate['sip_trunk_id'] ?? $duplicate['sipTrunkId'] ?? null;
            if ($id) {
                $this->deleteTrunk($id);
            }
        }

        // Create dispatch rules for each AI extension.
        $created = [];
        foreach ($this->buildDispatchRules() as $rule) {
            $created[] = $this->createDispatchRule($rule);
        }

        return $created;
    }

    /**
     * Call a livekit.SIP Twirp method. The body is always a JSON object:
     * Laravel encodes an empty array as `[]`, which Twirp rejects as
     * malformed.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function twirp(string $method, array $body = []): array
    {
        $response = Http::withToken($this->generateToken(), 'Bearer')
            ->withBody(json_encode((object) $body, JSON_THROW_ON_ERROR), 'application/json')
            ->post("{$this->apiUrl}/twirp/livekit.SIP/{$method}");

        if (! $response->successful()) {
            throw new \RuntimeException("LiveKit {$method} failed ({$response->status()}): {$response->body()}");
        }

        return $response->json() ?? [];
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
