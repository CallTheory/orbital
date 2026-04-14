<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\AsteriskAmiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Phase 7 — reload churn audit.
 *
 * The whole point of Phases 3–6 is that endpoint, queue, and
 * member changes don't churn Asterisk reloads at scale. This test
 * fakes the AMI service and the dialplan job to assert:
 *
 *   - Extension create/update/delete: **zero** AMI reloads,
 *     **zero** dialplan-job dispatches. ARA syncer handles it.
 *   - SipTrunk create/update/delete: same.
 *   - CallQueue create/update/delete: same.
 *   - RoutingRule create/update/delete: dispatches the dialplan
 *     job (which uses scoped `dialplan reload`, never a full
 *     `core reload`).
 *
 * If a future commit accidentally re-introduces a full reload from
 * the endpoint/queue path, this test fails loudly.
 */
class ReloadChurnTest extends TestCase
{
    use RefreshDatabase;

    public function test_extension_changes_do_not_dispatch_dialplan_job_or_call_ami_reload(): void
    {
        Bus::fake();
        $ami = $this->mock(AsteriskAmiService::class, function (MockInterface $m) {
            $m->shouldNotReceive('reload');
            $m->shouldNotReceive('reloadDialplan');
        });

        $team = $this->makeTeam();

        $ext = Extension::create([
            'team_id' => $team->id,
            'number' => '201',
            'type' => 'sip_phone',
            'sip_username' => 'a',
            'sip_password' => 'b',
            'is_active' => true,
        ]);

        $ext->update(['label' => 'Front Desk']);
        $ext->delete();

        Bus::assertNotDispatched(RegenerateTelephonyConfig::class);
    }

    public function test_sip_trunk_changes_do_not_dispatch_dialplan_job_or_call_ami_reload(): void
    {
        Bus::fake();
        $ami = $this->mock(AsteriskAmiService::class, function (MockInterface $m) {
            $m->shouldNotReceive('reload');
            $m->shouldNotReceive('reloadDialplan');
        });

        $trunk = SipTrunk::create([
            'team_id' => null,
            'name' => 'Provider',
            'host' => 'sip.test',
            'port' => 5060,
            'transport' => 'udp',
            'username' => 'orbital',
            'password' => 'pw',
            'is_active' => true,
        ]);

        $trunk->update(['port' => 5061]);
        $trunk->delete();

        Bus::assertNotDispatched(RegenerateTelephonyConfig::class);
    }

    public function test_call_queue_changes_do_not_dispatch_dialplan_job_or_call_ami_reload(): void
    {
        Bus::fake();
        $ami = $this->mock(AsteriskAmiService::class, function (MockInterface $m) {
            $m->shouldNotReceive('reload');
            $m->shouldNotReceive('reloadDialplan');
        });

        $team = $this->makeTeam();
        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'Support',
            'strategy' => 'ringall',
            'timeout' => 30,
        ]);

        $queue->update(['strategy' => 'rrmemory']);
        $queue->delete();

        Bus::assertNotDispatched(RegenerateTelephonyConfig::class);
    }

    public function test_routing_rule_changes_dispatch_the_scoped_dialplan_job(): void
    {
        Bus::fake();

        $team = $this->makeTeam();
        $trunk = SipTrunk::create([
            'team_id' => null,
            'name' => 'Provider',
            'host' => 'sip.test',
            'port' => 5060,
            'transport' => 'udp',
            'is_active' => true,
        ]);

        // Trunk creation already dispatched zero (verified above).
        // Routing rule changes are the only model that should
        // queue the dialplan job.
        Bus::assertNotDispatched(RegenerateTelephonyConfig::class);

        RoutingRule::create([
            'team_id' => $team->id,
            'sip_trunk_id' => $trunk->id,
            'name' => 'Inbound',
            'match_type' => 'did',
            'match_pattern' => '+15550100',
            'destination_type' => 'extension',
            'destination_id' => 201,
            'priority' => 0,
            'is_active' => true,
        ]);

        Bus::assertDispatched(
            RegenerateTelephonyConfig::class,
            fn (RegenerateTelephonyConfig $job) => $job->teamId === $team->id,
        );
    }

    protected function makeTeam(string $name = 'Acme'): Team
    {
        $owner = User::factory()->create();
        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
        ]);
    }
}
