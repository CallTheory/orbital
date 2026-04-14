<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\SipTrunk;
use App\Services\Telephony\Realtime\EndpointSyncer;
use App\Services\Telephony\Realtime\QueueMemberSyncer;
use App\Services\Telephony\Realtime\QueueSyncer;
use App\Services\Telephony\Realtime\TrunkSyncer;
use Illuminate\Console\Command;

/**
 * Walks every Extension, SipTrunk, and CallQueue in the database
 * and re-syncs them into the Asterisk Realtime (ARA) tables. Used:
 *
 *   - **after `migrate:fresh --seed`** to populate the empty ARA
 *     tables from whatever the seeders created. Called from
 *     DemoTenantSeeder so the dev walkthrough always has live
 *     endpoints.
 *
 *   - **as a recovery tool** if you suspect drift between the
 *     domain tables and the ARA tables (model wrote outside the
 *     observer, manual DB tweak, etc.). Idempotent — repeated
 *     runs converge on the same state.
 *
 *   - **after a Phase-3 image rebuild** where the ARA tables exist
 *     but the sync layer hasn't run yet.
 *
 * Usage:
 *   ./vendor/bin/sail artisan orbital:resync-realtime
 *   ./vendor/bin/sail artisan orbital:resync-realtime --truncate
 */
class ResyncRealtimeCommand extends Command
{
    protected $signature = 'orbital:resync-realtime
        {--truncate : Wipe ARA tables before resyncing (clean rebuild)}';

    protected $description = 'Re-sync all extensions, trunks, and queues into the Asterisk Realtime tables.';

    public function handle(
        EndpointSyncer $endpointSyncer,
        TrunkSyncer $trunkSyncer,
        QueueSyncer $queueSyncer,
        QueueMemberSyncer $queueMemberSyncer,
    ): int {
        if ($this->option('truncate')) {
            $this->info('Truncating ARA tables...');
            \DB::table('queue_members')->delete();
            \DB::table('queues')->delete();
            \DB::table('ps_endpoint_id_ips')->delete();
            \DB::table('ps_endpoints')->delete();
            \DB::table('ps_auths')->delete();
            \DB::table('ps_aors')->delete();
        }

        // Trunks first — they create the providers' inbound endpoints
        // and identify-by-IP rules, which downstream extension and
        // queue logic doesn't depend on but is cleanest to do first.
        $trunks = SipTrunk::withoutGlobalScopes()->get();
        $this->info("Syncing {$trunks->count()} trunk(s)...");
        foreach ($trunks as $trunk) {
            $trunkSyncer->sync($trunk);
            $this->line('  ✓ '.$trunk->name);
        }

        // Extensions next — every Extension becomes an ARA endpoint
        // + auth + aor row. WebRTC, SIP phone, ATA, AI agent all
        // dispatched on type inside the syncer.
        $extensions = Extension::withoutGlobalScopes()->get();
        $this->info("Syncing {$extensions->count()} extension(s)...");
        foreach ($extensions as $extension) {
            $endpointSyncer->sync($extension);
            $this->line('  ✓ '.$extension->realtimeEndpointId().' ('.$extension->type.')');
        }

        // Queues last — they reference extensions transitively via
        // queue_members, so doing them after extensions ensures
        // every member's PJSIP/<id> interface resolves.
        $queues = CallQueue::withoutGlobalScopes()->get();
        $this->info("Syncing {$queues->count()} queue(s)...");
        foreach ($queues as $queue) {
            $queueSyncer->sync($queue);
            $queueMemberSyncer->syncForQueue($queue);
            $this->line('  ✓ '.$queue->asteriskName());
        }

        $this->newLine();
        $this->info('ARA resync complete.');

        return self::SUCCESS;
    }
}
