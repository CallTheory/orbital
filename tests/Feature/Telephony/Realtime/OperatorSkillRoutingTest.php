<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony\Realtime;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\Skill;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\Realtime\QueueMemberSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6 — operator skills + shared pools.
 *
 * QueueMemberSyncer is the brain of the shared-pool routing model:
 * it walks every operator with an active extension, scores them
 * against the queue's required skills, and writes one
 * `queue_members` row per qualified operator with a penalty
 * derived from skill level + client tier.
 *
 * This suite locks in:
 *   - operators with no overlap with required skills are excluded
 *   - operators with a strong overlap get a lower penalty (ring first)
 *   - client tier shifts the base penalty (enterprise < pro < free)
 *   - changing required skills triggers a clean recompute (old rows
 *     for now-unqualified operators disappear)
 *   - the AgentGroup fallback still works when a queue declares no
 *     required skills (legacy compatibility)
 */
class OperatorSkillRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_with_required_skill_becomes_a_queue_member(): void
    {
        $team = $this->makeTeam('Acme', 'pro');
        $skill = Skill::create([
            'slug' => 'medical-intake',
            'name' => 'Medical Intake',
            'category' => 'specialty',
            'is_active' => true,
        ]);

        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'Patient Intake',
            'strategy' => 'rrmemory',
            'timeout' => 30,
        ]);
        $queue->requiredSkills()->attach($skill->id, ['weight' => 5]);

        $operator = $this->makeOperator('Op One', '5001');
        $operator->skills()->attach($skill->id, ['level' => 4]);

        app(QueueMemberSyncer::class)->syncForQueue($queue->fresh());

        $row = DB::table('queue_members')
            ->where('queue_name', $queue->asteriskName())
            ->where('interface', 'PJSIP/'.$operator->extensions->first()->realtimeEndpointId())
            ->first();

        $this->assertNotNull($row);
        // pro tier base 5 - bonus(min(4, 20/12)=1) = 4
        $this->assertSame(4, (int) $row->penalty);
    }

    public function test_operator_without_required_skill_is_excluded(): void
    {
        $team = $this->makeTeam();
        $intakeSkill = Skill::create(['slug' => 'intake', 'name' => 'Intake', 'category' => 'specialty']);
        $billingSkill = Skill::create(['slug' => 'billing', 'name' => 'Billing', 'category' => 'specialty']);

        $queue = CallQueue::create(['team_id' => $team->id, 'name' => 'Intake', 'strategy' => 'ringall', 'timeout' => 30]);
        $queue->requiredSkills()->attach($intakeSkill->id, ['weight' => 5]);

        $operator = $this->makeOperator('Wrong Op', '5002');
        $operator->skills()->attach($billingSkill->id, ['level' => 5]);

        app(QueueMemberSyncer::class)->syncForQueue($queue->fresh());

        $count = DB::table('queue_members')->where('queue_name', $queue->asteriskName())->count();
        $this->assertSame(0, $count);
    }

    public function test_enterprise_tier_gets_lower_base_penalty_than_free(): void
    {
        $skill = Skill::create(['slug' => 'support', 'name' => 'Support', 'category' => 'specialty']);
        $operator = $this->makeOperator('Op', '5003');
        $operator->skills()->attach($skill->id, ['level' => 1]); // weak match → no bonus

        $enterprise = $this->makeTeam('Enterprise Co', 'enterprise');
        $free = $this->makeTeam('Free Co', 'free');

        $entQueue = CallQueue::create(['team_id' => $enterprise->id, 'name' => 'Support', 'strategy' => 'ringall', 'timeout' => 30]);
        $entQueue->requiredSkills()->attach($skill->id, ['weight' => 1]);
        $freeQueue = CallQueue::create(['team_id' => $free->id, 'name' => 'Support', 'strategy' => 'ringall', 'timeout' => 30]);
        $freeQueue->requiredSkills()->attach($skill->id, ['weight' => 1]);

        $sync = app(QueueMemberSyncer::class);
        $sync->syncForQueue($entQueue->fresh());
        $sync->syncForQueue($freeQueue->fresh());

        $entRow = DB::table('queue_members')->where('queue_name', $entQueue->asteriskName())->first();
        $freeRow = DB::table('queue_members')->where('queue_name', $freeQueue->asteriskName())->first();

        $this->assertNotNull($entRow);
        $this->assertNotNull($freeRow);
        $this->assertLessThan((int) $freeRow->penalty, (int) $entRow->penalty);
    }

    public function test_strong_skill_match_lowers_penalty(): void
    {
        $team = $this->makeTeam('Acme', 'pro');
        $skill = Skill::create(['slug' => 'spanish', 'name' => 'Spanish', 'category' => 'language']);

        $queue = CallQueue::create(['team_id' => $team->id, 'name' => 'Bilingual', 'strategy' => 'ringall', 'timeout' => 30]);
        $queue->requiredSkills()->attach($skill->id, ['weight' => 10]);

        $weak = $this->makeOperator('Weak', '5010');
        $weak->skills()->attach($skill->id, ['level' => 1]);

        $strong = $this->makeOperator('Strong', '5011');
        $strong->skills()->attach($skill->id, ['level' => 5]);

        app(QueueMemberSyncer::class)->syncForQueue($queue->fresh());

        $weakRow = DB::table('queue_members')
            ->where('queue_name', $queue->asteriskName())
            ->where('interface', 'PJSIP/'.$weak->extensions->first()->realtimeEndpointId())
            ->first();
        $strongRow = DB::table('queue_members')
            ->where('queue_name', $queue->asteriskName())
            ->where('interface', 'PJSIP/'.$strong->extensions->first()->realtimeEndpointId())
            ->first();

        $this->assertLessThan((int) $weakRow->penalty, (int) $strongRow->penalty);
    }

    public function test_re_sync_removes_operators_who_lost_their_qualifying_skill(): void
    {
        $team = $this->makeTeam();
        $skill = Skill::create(['slug' => 'cert', 'name' => 'Cert', 'category' => 'compliance']);

        $queue = CallQueue::create(['team_id' => $team->id, 'name' => 'Certified', 'strategy' => 'ringall', 'timeout' => 30]);
        $queue->requiredSkills()->attach($skill->id, ['weight' => 5]);

        $operator = $this->makeOperator('Op', '5020');
        $operator->skills()->attach($skill->id, ['level' => 3]);

        $sync = app(QueueMemberSyncer::class);
        $sync->syncForQueue($queue->fresh());
        $this->assertSame(1, DB::table('queue_members')->where('queue_name', $queue->asteriskName())->count());

        // Operator loses their cert → resync should drop them.
        $operator->skills()->detach($skill->id);
        $sync->syncForQueue($queue->fresh());

        $this->assertSame(0, DB::table('queue_members')->where('queue_name', $queue->asteriskName())->count());
    }

    public function test_queue_with_no_required_skills_falls_back_to_agent_group(): void
    {
        // Legacy path: a queue without required skills uses the
        // existing AgentGroup pivot. Phase 4's tests already cover
        // the empty case (no agent group → no members), so here we
        // just verify the fallback is reached and produces a row
        // when the agent group exists.
        $team = $this->makeTeam();
        $group = \App\Models\AgentGroup::create([
            'name' => 'fallback',
            'label' => 'Fallback Pool',
            'is_active' => true,
        ]);
        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'Legacy',
            'strategy' => 'ringall',
            'timeout' => 30,
            'agent_group_id' => $group->id,
        ]);

        $operator = $this->makeOperator('Legacy Op', '5030');
        \App\Models\AgentGroupMember::create([
            'agent_group_id' => $group->id,
            'member_type' => User::class,
            'member_id' => $operator->id,
            'priority' => 0,
            'penalty' => 0,
        ]);

        app(QueueMemberSyncer::class)->syncForQueue($queue->fresh());

        // Legacy AgentGroup path resolves member name from the
        // Extension (label or number), not the User. We just need
        // a row to exist — the exact name comes from the extension.
        $rows = DB::table('queue_members')->where('queue_name', $queue->asteriskName())->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Ext 5030', $rows[0]->membername);
    }

    // ── helpers ─────────────────────────────────────────────────

    protected function makeTeam(string $name = 'Acme', string $tier = 'free'): Team
    {
        $owner = User::factory()->create();
        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
            'tier' => $tier,
        ]);
    }

    protected function makeOperator(string $name, string $extNumber): User
    {
        $user = User::factory()->create(['name' => $name]);
        Extension::create([
            'team_id' => null,
            'number' => $extNumber,
            'type' => 'staff_softphone',
            'sip_username' => 'op'.$extNumber,
            'sip_password' => 'secret',
            'transport' => 'wss',
            'context' => 'internal',
            'is_active' => true,
            'assignable_type' => User::class,
            'assignable_id' => $user->id,
        ]);
        return $user->fresh();
    }
}
