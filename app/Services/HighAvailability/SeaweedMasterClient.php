<?php

declare(strict_types=1);

namespace App\Services\HighAvailability;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for SeaweedFS master REST endpoints at :9333. Used by
 * Failover Central to show Raft leader + per-node topology.
 */
class SeaweedMasterClient
{
    /** @param list<string> $masters Hostnames, e.g. ['seaweed-master-1','seaweed-master-2','seaweed-master-3'] */
    public function __construct(
        protected array $masters = [
            'seaweed-master-1',
            'seaweed-master-2',
            'seaweed-master-3',
        ],
        protected int $port = 9333,
        protected float $timeout = 2.0,
    ) {}

    /**
     * @return array{leader: ?string, peers: list<string>, volumes: list<array<string,mixed>>, filers: list<array<string,mixed>>}|null
     */
    public function status(): ?array
    {
        foreach ($this->masters as $host) {
            try {
                $cluster = Http::timeout($this->timeout)
                    ->get("http://{$host}:{$this->port}/cluster/status")
                    ->json();
                $topo = Http::timeout($this->timeout)
                    ->get("http://{$host}:{$this->port}/dir/status")
                    ->json();

                $volumes = [];
                $dcs = $topo['Topology']['DataCenters'] ?? [];
                foreach ($dcs as $dc) {
                    foreach ($dc['Racks'] ?? [] as $rack) {
                        foreach ($rack['DataNodes'] ?? [] as $node) {
                            $volumes[] = [
                                'url' => $node['Url'] ?? '',
                                'public_url' => $node['PublicUrl'] ?? '',
                                'volumes' => $node['Volumes'] ?? 0,
                                'max' => $node['Max'] ?? 0,
                            ];
                        }
                    }
                }

                return [
                    'leader' => $cluster['Leader'] ?? null,
                    'peers' => $cluster['Peers'] ?? [],
                    'volumes' => $volumes,
                    // Filer list comes from a separate endpoint;
                    // wire-up deferred until we have multiple filers.
                    'filers' => [],
                ];
            } catch (\Throwable $e) {
                Log::warning('seaweedfs.master: {host} unreachable', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return null;
    }
}
