<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telephony\LiveKitConfigService;
use App\Services\Telephony\Realtime\InfrastructureEndpointSyncer;
use Illuminate\Console\Command;

/**
 * Provisions LiveKit SIP infrastructure — inbound trunk and
 * dispatch rules — so the SIP bridge knows how to route calls
 * from Asterisk to AI agent rooms.
 *
 * Run after:
 *   - Adding/removing/changing AI agent extensions
 *   - First install (no trunk or rules exist yet)
 *   - Resetting LiveKit state (container rebuild, etc.)
 *
 * Safe to run repeatedly — deletes existing rules and recreates.
 *
 * Usage:
 *   sail artisan orbital:sync-livekit-sip
 */
class SyncLiveKitSip extends Command
{
    protected $signature = 'orbital:sync-livekit-sip';

    protected $description = 'Provision both ends of the Asterisk ↔ LiveKit SIP path: Asterisk\'s livekit endpoint, and LiveKit\'s inbound trunk and AI dispatch rule.';

    public function handle(LiveKitConfigService $service, InfrastructureEndpointSyncer $endpoints): int
    {
        // Asterisk side first: it's only database rows, so it works even
        // while LiveKit is still starting (e.g. on a first install).
        $endpoints->sync();
        $this->info('Asterisk: livekit endpoint and anonymous context ('.config('telephony.anonymous_context').') written.');

        $this->info('Syncing LiveKit SIP configuration...');

        try {
            $created = $service->syncDispatchRules();
            $this->info('Created '.count($created).' dispatch rule(s).');

            foreach ($created as $rule) {
                $ruleData = $rule['rule'] ?? $rule;
                $prefix = $ruleData['dispatchRuleCallee']['roomPrefix']
                    ?? $ruleData['rule']['dispatchRuleCallee']['roomPrefix']
                    ?? 'unknown';
                $this->line("  → {$prefix}");
            }

            $this->newLine();
            $this->info('Done. AI agent calls from Asterisk will now route through LiveKit.');
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
