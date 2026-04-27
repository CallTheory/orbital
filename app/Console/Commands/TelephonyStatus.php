<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\SipTrunk;
use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\LiveKitConfigService;
use Illuminate\Console\Command;

class TelephonyStatus extends Command
{
    protected $signature = 'orbital:status';

    protected $description = 'Check the status of telephony services (Asterisk, LiveKit)';

    public function handle(AsteriskAmiService $ami, LiveKitConfigService $livekit): int
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

        // Database counts
        $this->newLine();
        $this->info('  Database Summary:');
        $this->line('    Extensions: '.Extension::withoutGlobalScopes()->count());
        $this->line('    SIP Trunks: '.SipTrunk::withoutGlobalScopes()->count());
        $this->line('    AI Agents:  '.AgentPersona::withoutGlobalScopes()->where('is_active', true)->count());
        $this->line('    Queues:     '.CallQueue::withoutGlobalScopes()->count());

        return self::SUCCESS;
    }
}
