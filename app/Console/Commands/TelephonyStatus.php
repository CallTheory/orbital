<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RtpengineNode;
use App\Models\SipTrunk;
use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\LiveKitConfigService;
use App\Services\Telephony\RtpengineService;
use App\Support\DefaultCredentials;
use Illuminate\Console\Command;

class TelephonyStatus extends Command
{
    protected $signature = 'orbital:status';

    protected $description = 'Check the status of telephony services (Asterisk, LiveKit)';

    public function handle(AsteriskAmiService $ami, LiveKitConfigService $livekit, RtpengineService $rtpengine): int
    {
        $this->info('Checking Orbital telephony services...');
        $this->newLine();

        // Asterisk AMI
        $this->output->write('  Asterisk AMI ... ');
        $channels = $ami->getActiveChannels();
        if ($channels !== []) {
            $this->info('OK ('.count($channels).' active channels)');
        } else {
            // Empty channels could mean connected with no calls, or failed
            $this->warn('Connected (0 active channels)');
        }

        // LiveKit
        $this->output->write('  LiveKit Server ... ');
        if ($livekit->healthCheck()) {
            $this->info('OK');
        } else {
            $this->error('UNREACHABLE');
        }

        // rtpengine — NG ping fan-out across every active node
        $totalActive = RtpengineNode::active()->count();
        if ($totalActive === 0) {
            $this->output->write('  rtpengine ... ');
            $this->warn('No active nodes registered');
        } else {
            $results = $rtpengine->pingAll();
            $up = count(array_filter($results));
            $this->output->write('  rtpengine ... ');
            if ($up === $totalActive) {
                $this->info("OK ({$up}/{$totalActive} responding)");
            } elseif ($up === 0) {
                $this->error("DOWN (0/{$totalActive} responding)");
            } else {
                $this->warn("DEGRADED ({$up}/{$totalActive} responding)");
            }
            foreach ($results as $hostname => $ok) {
                $this->line('    '.($ok ? '✓' : '✗').' '.$hostname);
            }
        }

        // Database counts
        $this->newLine();
        $this->info('  Database Summary:');
        $this->line('    Extensions: '.Extension::withoutGlobalScopes()->count());
        $this->line('    SIP Trunks: '.SipTrunk::withoutGlobalScopes()->count());
        $this->line('    AI Agents:  '.AgentPersona::withoutGlobalScopes()->where('is_active', true)->count());
        $this->line('    Queues:     '.CallQueue::withoutGlobalScopes()->count());

        $this->reportUnrotatedDefaults();

        return self::SUCCESS;
    }

    /**
     * Warn about credentials still sitting at their shipped development
     * defaults. SECURITY.md points operators here before go-live, so it
     * has to actually check. The list lives in
     * {@see DefaultCredentials}.
     */
    private function reportUnrotatedDefaults(): void
    {
        $unrotated = DefaultCredentials::unrotated();

        $this->newLine();

        if ($unrotated === []) {
            $this->info('  Credentials: no shipped defaults still in use.');

            return;
        }

        $this->warn('  Credentials: '.count($unrotated).' still at their shipped default:');

        foreach ($unrotated as $label) {
            $this->line('    ! '.$label);
        }

        $this->line('    Rotate these before this installation takes real calls. See SECURITY.md.');
    }
}
