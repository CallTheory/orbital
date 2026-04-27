<?php

declare(strict_types=1);

namespace App\Services\HighAvailability;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the Patroni REST API exposed on port 8008 of every
 * Patroni node. Used by the Failover Central admin page to show
 * per-node role + replication lag and to trigger switchovers.
 *
 * Patroni REST conventions:
 *   GET  /cluster    — full cluster state (leader + members)
 *   GET  /primary    — 200 on current leader, 503 on replicas
 *   GET  /replica    — 200 on replicas, 503 on leader
 *   POST /switchover — {"leader":"X","candidate":"Y"} — graceful
 *                       handoff (leader steps down, candidate
 *                       promoted, old leader rejoins as replica)
 *   POST /failover   — forced promotion of candidate — used when
 *                       the current leader is unreachable
 *
 * All methods return safe defaults on error and log warnings so
 * the UI can render partial state when a node is unreachable.
 */
class PatroniClient
{
    /** @param list<string> $nodes Node hostnames, e.g. ['patroni-1','patroni-2','patroni-3'] */
    public function __construct(
        protected array $nodes = ['patroni-1', 'patroni-2', 'patroni-3'],
        protected int $port = 8008,
        protected float $timeout = 2.0,
        protected string $restUser = 'patroni',
        protected ?string $restPassword = null,
    ) {
        // Match the Patroni container env (PATRONI_REST_PASSWORD).
        $this->restPassword ??= (string) config(
            'services.patroni.rest_password',
            env('PATRONI_REST_PASSWORD', 'patroni-rest-dev'),
        );
    }

    /**
     * Return the full cluster view from whichever node answers
     * first. Patroni REST serves the same cluster state from
     * every node, so we round-robin and return on first success.
     *
     * @return array{leader: ?string, members: list<array{name: string, host: string, role: string, state: string, lag: ?int, timeline: ?int}>}|null
     */
    public function cluster(): ?array
    {
        foreach ($this->nodes as $node) {
            try {
                $resp = Http::timeout($this->timeout)
                    ->get("http://{$node}:{$this->port}/cluster");
                if ($resp->successful()) {
                    return $this->normalizeCluster($resp->json());
                }
            } catch (\Throwable $e) {
                Log::warning('patroni.cluster: node {node} unreachable', [
                    'node' => $node,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array{leader: ?string, members: list<array{name: string, host: string, role: string, state: string, lag: ?int, timeline: ?int}>}
     */
    protected function normalizeCluster(array $raw): array
    {
        $leader = null;
        $members = [];
        foreach ($raw['members'] ?? [] as $m) {
            if (($m['role'] ?? null) === 'leader') {
                $leader = $m['name'] ?? null;
            }
            $members[] = [
                'name' => $m['name'] ?? 'unknown',
                'host' => $m['host'] ?? 'unknown',
                'role' => $m['role'] ?? 'unknown',
                'state' => $m['state'] ?? 'unknown',
                'lag' => isset($m['lag']) && is_numeric($m['lag']) ? (int) $m['lag'] : null,
                'timeline' => isset($m['timeline']) ? (int) $m['timeline'] : null,
            ];
        }

        return ['leader' => $leader, 'members' => $members];
    }

    /**
     * Trigger a graceful switchover from the current leader to the
     * named candidate. Returns [success, output].
     *
     * @return array{0: bool, 1: string}
     */
    public function switchover(string $candidate): array
    {
        $cluster = $this->cluster();
        $leader = $cluster['leader'] ?? null;
        if ($leader === null) {
            return [false, 'no leader currently'];
        }
        if ($leader === $candidate) {
            return [false, "candidate '{$candidate}' is already the leader"];
        }

        try {
            $resp = Http::timeout(30)
                ->withBasicAuth($this->restUser, $this->restPassword)
                ->post("http://{$leader}:{$this->port}/switchover", [
                    'leader' => $leader,
                    'candidate' => $candidate,
                ]);

            return [$resp->successful(), (string) $resp->body()];
        } catch (\Throwable $e) {
            Log::warning('patroni.switchover failed', ['error' => $e->getMessage()]);

            return [false, $e->getMessage()];
        }
    }

    /**
     * Force-promote a candidate (used when the current leader is
     * unreachable and switchover wouldn't work).
     *
     * @return array{0: bool, 1: string}
     */
    public function failover(string $candidate): array
    {
        // Patroni's /failover accepts the RPC on any node; route
        // through the first reachable one.
        foreach ($this->nodes as $node) {
            try {
                $resp = Http::timeout(30)
                    ->withBasicAuth($this->restUser, $this->restPassword)
                    ->post("http://{$node}:{$this->port}/failover", [
                        'candidate' => $candidate,
                    ]);

                return [$resp->successful(), (string) $resp->body()];
            } catch (\Throwable $e) {
                Log::warning('patroni.failover: {node} unreachable', [
                    'node' => $node,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [false, 'no Patroni node reachable'];
    }
}
