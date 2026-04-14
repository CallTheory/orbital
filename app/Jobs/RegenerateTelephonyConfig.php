<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Telephony\AsteriskConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Regenerates Asterisk dialplan files and triggers a scoped reload.
 *
 * The job is per-tenant: dispatching with a `teamId` only rewrites
 * that tenant's dialplan file plus the from-trunk dispatcher and
 * triggers `dialplan reload` (NOT `core reload`), so a single
 * tenant's RoutingRule edit doesn't churn pjsip/queues/codec config
 * for the rest of the platform.
 *
 * Dispatching with a null `teamId` regenerates everything — used by
 * the bootstrap command and after `migrate:fresh --seed`.
 *
 * **Phases 3 and 4** will move endpoints + queues out of generated
 * files into ARA tables, at which point this job is only triggered
 * for routing-rule / DID changes.
 */
class RegenerateTelephonyConfig implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 10;

    public function __construct(
        public ?int $teamId = null,
    ) {}

    public function handle(AsteriskConfigService $service): void
    {
        Log::info('Regenerating telephony config', ['team_id' => $this->teamId]);

        if ($this->teamId !== null) {
            // Scoped path: only this tenant's dialplan file plus the
            // shared from-trunk dispatcher (since DIDs in this
            // tenant's routing rules might have changed which trunk
            // patterns dispatch where).
            $service->writeDialplanForTenant($this->teamId);

            if ($service->reloadDialplan()) {
                Log::info('Dialplan reloaded for tenant', ['team_id' => $this->teamId]);
            } else {
                Log::warning('Dialplan reload failed — file written but not applied', ['team_id' => $this->teamId]);
            }
            return;
        }

        // Full regen: every tenant's dialplan + the dispatcher +
        // the index. Used by bootstrap, fresh seed, and the
        // `orbital:generate-config` artisan command.
        $service->writeConfigs(null);
        $service->writeDialplanIndex();

        if ($service->reloadAsterisk()) {
            Log::info('Asterisk fully reloaded after global config regen');
        } else {
            Log::warning('Asterisk reload failed — config written but not applied');
        }
    }

    public function uniqueId(): string
    {
        return 'regen-telephony-'.($this->teamId ?? 'all');
    }
}
