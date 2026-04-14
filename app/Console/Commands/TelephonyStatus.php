<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
        $this->line('    Extensions: '.\App\Models\Extension::withoutGlobalScopes()->count());
        $this->line('    SIP Trunks: '.\App\Models\SipTrunk::withoutGlobalScopes()->count());
        $this->line('    AI Agents:  '.\App\Models\AgentPersona::withoutGlobalScopes()->where('is_active', true)->count());
        $this->line('    Queues:     '.\App\Models\CallQueue::withoutGlobalScopes()->count());

        return self::SUCCESS;
    }
}
