<?php

declare(strict_types=1);

namespace App\Services\Telephony;

/**
 * Finds the Asterisk pods the SIP edge should dispatch to, by resolving
 * the chart's headless Asterisk Service. Asterisk runs on the node
 * network on Kubernetes, so each A record is a node's private IP — the
 * address the edge VMs reach it on. The Service publishes pods before
 * they're ready; Kamailio's OPTIONS probing keeps calls off those.
 */
class AsteriskPeerResolver
{
    /**
     * @return list<string> sorted, de-duplicated IPv4 addresses
     */
    public function addresses(): array
    {
        $host = (string) config('telephony.kamailio.asterisk_discovery_host', '');
        if ($host === '') {
            return [];
        }

        $ips = @gethostbynamel($host);
        if ($ips === false) {
            return [];
        }

        $ips = array_values(array_unique($ips));
        sort($ips);

        return $ips;
    }
}
