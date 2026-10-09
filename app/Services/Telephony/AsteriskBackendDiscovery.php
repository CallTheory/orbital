<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\AsteriskBackend;

/**
 * Keeps the AsteriskBackend registry in step with the Asterisk pods on
 * Kubernetes, so the SIP Proxy page lists, drains and counts the real
 * nodes. Rows are keyed by node IP — the address in the edges'
 * dispatcher list — and labelled with the pod name.
 *
 * Backends that are no longer discovered are deactivated, not deleted,
 * so an operator's drain on them comes back if the pod returns to that
 * node. Nothing is deactivated when discovery finds no pods at all: a
 * DNS blip shouldn't empty the registry.
 */
class AsteriskBackendDiscovery
{
    public function __construct(
        protected AsteriskPeerResolver $peers,
    ) {}

    public function enabled(): bool
    {
        return (string) config('telephony.kamailio.asterisk_discovery_host', '') !== '';
    }

    /**
     * @return array{discovered: int, deactivated: int}
     */
    public function sync(): array
    {
        $pods = $this->peers->pods();
        if ($pods === []) {
            return ['discovered' => 0, 'deactivated' => 0];
        }

        $order = 10;
        foreach ($pods as $name => $ip) {
            AsteriskBackend::query()->updateOrCreate(
                ['hostname' => $ip],
                [
                    'node_name' => $name,
                    'display_name' => $name,
                    'sip_port' => 5060,
                    'ami_host' => $ip,
                    'ami_port' => 5038,
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );
            $order += 10;
        }

        $deactivated = AsteriskBackend::query()
            ->active()
            ->whereNotIn('hostname', array_values($pods))
            ->update(['is_active' => false]);

        return ['discovered' => count($pods), 'deactivated' => $deactivated];
    }
}
