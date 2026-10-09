<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telephony\AsteriskBackendDiscovery;
use Illuminate\Console\Command;

/**
 * Sync the AsteriskBackend registry from the Asterisk pods (Kubernetes
 * only; a no-op unless ASTERISK_DISCOVERY_HOST is set).
 */
class SyncAsteriskBackends extends Command
{
    protected $signature = 'orbital:sync-asterisk-backends';

    protected $description = 'Sync Asterisk backends from the Kubernetes Asterisk pods';

    public function handle(AsteriskBackendDiscovery $discovery): int
    {
        if (! $discovery->enabled()) {
            $this->info('Asterisk discovery is off (ASTERISK_DISCOVERY_HOST unset); nothing to do.');

            return self::SUCCESS;
        }

        $result = $discovery->sync();
        $this->info("Discovered {$result['discovered']} Asterisk pod(s); deactivated {$result['deactivated']} stale backend(s).");

        return self::SUCCESS;
    }
}
