<?php

declare(strict_types=1);

namespace App\Services\HighAvailability;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Predis\Client as Predis;

/**
 * Client for Valkey Sentinel running on port 26379. Used by the
 * Failover Central admin page to show Sentinel's view of the
 * replica set and to trigger manual failovers.
 *
 * We talk to Sentinel with the same Predis library that Laravel
 * uses for the data plane — just against a different port and
 * with no AUTH (sentinels intentionally run unauthenticated on
 * the private docker network so SeaweedFS filer's go-redis
 * driver can discover the primary).
 *
 * Master group name is `orbital` (matches sentinel.conf.tmpl and
 * Laravel's database.php sentinels config).
 */
class SentinelClient
{
    /** @param list<string> $sentinels Hostnames, e.g. ['sentinel-1','sentinel-2','sentinel-3'] */
    public function __construct(
        protected array $sentinels = ['sentinel-1', 'sentinel-2', 'sentinel-3'],
        protected int $port = 26379,
        protected string $masterName = 'orbital',
        protected float $timeout = 2.0,
    ) {}

    /**
     * @return array{master: ?array<string,string>, replicas: list<array<string,string>>, sentinels: list<array<string,string>>}|null
     */
    public function status(): ?array
    {
        foreach ($this->sentinels as $host) {
            try {
                $c = $this->connect($host);
                $master = $c->executeRaw(['SENTINEL', 'master', $this->masterName]);
                $replicas = $c->executeRaw(['SENTINEL', 'replicas', $this->masterName]);
                $others = $c->executeRaw(['SENTINEL', 'sentinels', $this->masterName]);

                return [
                    'master' => $this->pairsToAssoc(is_array($master) ? $master : []),
                    'replicas' => array_map(
                        fn ($r) => $this->pairsToAssoc(is_array($r) ? $r : []),
                        is_array($replicas) ? $replicas : []
                    ),
                    'sentinels' => array_map(
                        fn ($s) => $this->pairsToAssoc(is_array($s) ? $s : []),
                        is_array($others) ? $others : []
                    ),
                ];
            } catch (\Throwable $e) {
                Log::warning('sentinel.status: {host} unreachable', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return null;
    }

    /**
     * Force a failover via SENTINEL FAILOVER — Sentinel picks the
     * best-replica candidate and promotes it, then reconfigures
     * the remaining replicas. No candidate selection from our
     * side; Sentinel knows which replica has the least lag.
     *
     * @return array{0: bool, 1: string}
     */
    public function forceFailover(): array
    {
        foreach ($this->sentinels as $host) {
            try {
                $c = $this->connect($host);
                $result = $c->executeRaw(['SENTINEL', 'failover', $this->masterName]);
                return [$result === 'OK', (string) $result];
            } catch (\Throwable $e) {
                Log::warning('sentinel.failover: {host} unreachable', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return [false, 'no sentinel reachable'];
    }

    protected function connect(string $host): Predis
    {
        return new Predis([
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $this->port,
            'read_write_timeout' => $this->timeout,
            'timeout' => $this->timeout,
        ]);
    }

    /**
     * Redis's SENTINEL command returns flat [key,value,key,value,...]
     * arrays. This converts them to assoc arrays for sane access.
     *
     * @param  list<mixed> $pairs
     * @return array<string, string>
     */
    protected function pairsToAssoc(array $pairs): array
    {
        $out = [];
        $count = count($pairs);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $out[(string) $pairs[$i]] = (string) $pairs[$i + 1];
        }
        return $out;
    }
}
