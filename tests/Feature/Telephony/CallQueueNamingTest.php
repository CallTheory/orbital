<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\CallQueue;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The Asterisk side of the system has a global queue/context
 * namespace — two tenants both naming a queue "support" would
 * collide and one of them would silently win. CallQueue::asteriskName()
 * fixes that with a `t{team_id}_{slug}` prefix and is the canonical
 * name everywhere downstream (the legacy generator, the new ARA
 * sync layer, the per-tenant dialplan blade, and the Realtime
 * QueueMemberSyncer).
 *
 * This suite locks in the naming rules so a future refactor of the
 * accessor can't quietly break collision-safety.
 */
class CallQueueNamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // CallQueue::create fires TelephonyObserver, which dispatches
        // RegenerateTelephonyConfig — that tries to write to the disk
        // path inside the orbital.test container. Not relevant to the
        // naming behavior we're verifying here.
        Bus::fake([RegenerateTelephonyConfig::class]);
    }

    public function test_tenant_queue_is_prefixed_with_team_id(): void
    {
        $team = $this->makeTeam();

        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'Support',
            'strategy' => 'ringall',
        ]);

        $this->assertSame("t{$team->id}_support", $queue->asteriskName());
    }

    public function test_two_tenants_can_have_the_same_queue_name_without_colliding(): void
    {
        $tenantA = $this->makeTeam('Acme');
        $tenantB = $this->makeTeam('Beta');

        $queueA = CallQueue::create(['team_id' => $tenantA->id, 'name' => 'Support', 'strategy' => 'ringall']);
        $queueB = CallQueue::create(['team_id' => $tenantB->id, 'name' => 'Support', 'strategy' => 'ringall']);

        $this->assertNotSame($queueA->asteriskName(), $queueB->asteriskName());
        $this->assertSame("t{$tenantA->id}_support", $queueA->asteriskName());
        $this->assertSame("t{$tenantB->id}_support", $queueB->asteriskName());
    }

    public function test_platform_queue_with_null_team_is_unprefixed(): void
    {
        $queue = CallQueue::create([
            'team_id' => null,
            'name' => 'Operator Pool',
            'strategy' => 'ringall',
        ]);

        $this->assertSame('operator_pool', $queue->asteriskName());
    }

    public function test_slug_normalizes_punctuation_and_case(): void
    {
        $team = $this->makeTeam();

        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => '  After-Hours / VIP!  ',
            'strategy' => 'ringall',
        ]);

        // After-Hours / VIP!  →  after_hours_vip
        $this->assertSame("t{$team->id}_after_hours_vip", $queue->asteriskName());
    }

    public function test_empty_or_purely_punctuation_name_falls_back_to_generic(): void
    {
        $team = $this->makeTeam();

        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => '!!!',
            'strategy' => 'ringall',
        ]);

        $this->assertSame("t{$team->id}_queue", $queue->asteriskName());
    }

    public function test_team_dialplan_context_uses_team_id(): void
    {
        $team = $this->makeTeam();

        $this->assertSame('tenant_'.$team->id, $team->dialplanContext());
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
