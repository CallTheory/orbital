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
    /**
     * @param  list<string>  $masters  Master hostnames.
     * @param  list<string>  $filers  Filer hostnames to probe via /healthz.
     */
    public function __construct(
        protected array $masters = [
            'seaweed-master-1',
            'seaweed-master-2',
            'seaweed-master-3',
        ],
        protected array $filers = [
            'seaweed-filer-1',
            'seaweed-filer-2',
        ],
        protected int $port = 9333,
        protected int $filerPort = 8888,
        protected float $timeout = 2.0,
    ) {}

    /**
     * @return array{
     *   leader: ?string,
     *   masters: list<array{host: string, role: string, reachable: bool}>,
     *   volumes: list<array<string,mixed>>,
     *   filers: list<array{host: string, reachable: bool}>
     * }|null
     */
    public function status(): ?array
    {
        $clusterPayload = null;
        $topology = null;
        $answeringMaster = null;

        foreach ($this->masters as $host) {
            try {
                $cluster = Http::timeout($this->timeout)
                    ->get("http://{$host}:{$this->port}/cluster/status")
                    ->json();
                $topo = Http::timeout($this->timeout)
                    ->get("http://{$host}:{$this->port}/dir/status")
                    ->json();
                $clusterPayload = $cluster;
                $topology = $topo;
                $answeringMaster = $host;
                break;
            } catch (\Throwable $e) {
                Log::warning('seaweedfs.master: {host} unreachable', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($clusterPayload === null) {
            return null;
        }

        $leader = $clusterPayload['Leader'] ?? null;
        // Strip trailing :19333 gRPC port from leader name so the
        // bare hostname matches the masters list for role mapping.
        $leaderHost = $leader ? preg_replace('/:\d+(\.\d+)?$/', '', (string) $leader) : null;

        $masters = [];
        foreach ($this->masters as $host) {
            $role = $leaderHost === $host ? 'leader' : 'follower';
            // Probe each master individually so a cluster with a
            // downed node still shows it as unreachable instead of
            // silently hiding it. Reuses the same :9333/cluster/status
            // endpoint — any healthy master answers.
            $reachable = true;
            try {
                Http::timeout($this->timeout)
                    ->get("http://{$host}:{$this->port}/cluster/status")
                    ->throw();
            } catch (\Throwable) {
                $reachable = false;
                $role = 'unreachable';
            }
            $masters[] = ['host' => $host, 'role' => $role, 'reachable' => $reachable];
        }

        $volumes = [];
        $dcs = $topology['Topology']['DataCenters'] ?? [];
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

        $filers = [];
        foreach ($this->filers as $host) {
            $reachable = false;
            try {
                $resp = Http::timeout($this->timeout)
                    ->get("http://{$host}:{$this->filerPort}/healthz");
                $reachable = $resp->successful();
            } catch (\Throwable) {
                $reachable = false;
            }
            $filers[] = ['host' => $host, 'reachable' => $reachable];
        }

        return [
            'leader' => $leaderHost,
            'masters' => $masters,
            'volumes' => $volumes,
            'filers' => $filers,
        ];
    }
}
