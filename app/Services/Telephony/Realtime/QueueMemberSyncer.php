<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the `queue_members` ARA table — i.e. WHO Asterisk
 * actually rings when a call enters a queue.
 *
 * **Phase 4** uses the existing AgentGroup pivot. Each CallQueue
 * has an optional `agent_group_id` pointing at an AgentGroup, and
 * AgentGroupMember rows attach Users / Extensions to the group via
 * a polymorphic pivot. The syncer expands the group into one
 * `queue_members` row per (interface) at sync time.
 *
 * **Phase 6** will replace the AgentGroup-driven path with a
 * skill-match algorithm: every operator carries a vector of
 * skills, every queue declares required skills, and members are
 * computed from the (operator × skill × queue × required-skill)
 * intersection. The signature of `syncForQueue()` stays the same
 * so the observer wiring doesn't change between phases.
 *
 * The interface string Asterisk uses to ring a member is
 * `PJSIP/{realtime_endpoint_id}` — same id we wrote into
 * ps_endpoints in the EndpointSyncer. For staff Users (operator
 * accounts), the member's allocated Extension provides the id.
 */
class QueueMemberSyncer
{
    /**
     * Recompute members for a single queue. Wipes existing rows
     * and inserts the freshly-computed set so deletions are
     * handled cleanly without diffing.
     */
    public function syncForQueue(CallQueue $queue): void
    {
        $queueName = $queue->asteriskName();
        $members = $this->collectMembers($queue);

        DB::transaction(function () use ($queueName, $members) {
            DB::table('queue_members')->where('queue_name', $queueName)->delete();
            if (! empty($members)) {
                DB::table('queue_members')->insert($members);
            }
        });
    }

    /**
     * Recompute every queue this operator is currently a member of.
     * Called when an operator is created / updated / deleted, when
     * their Extension changes, or in Phase 6 when their skill set
     * changes. For now it walks every queue whose AgentGroup
     * references the operator and re-syncs.
     */
    public function syncForOperator(User $operator): void
    {
        $queueIds = DB::table('agent_group_members')
            ->where('member_type', User::class)
            ->where('member_id', $operator->id)
            ->pluck('agent_group_id');

        if ($queueIds->isEmpty()) {
            return;
        }

        $queues = CallQueue::query()
            ->whereIn('agent_group_id', $queueIds)
            ->get();

        foreach ($queues as $queue) {
            $this->syncForQueue($queue);
        }
    }

    public function removeForOperator(User $operator): void
    {
        $extensionIds = $operator->extensions()->pluck('id');
        if ($extensionIds->isEmpty()) {
            return;
        }

        $interfaces = Extension::query()
            ->whereIn('id', $extensionIds)
            ->get()
            ->map(fn (Extension $e) => 'PJSIP/'.$e->realtimeEndpointId())
            ->all();

        DB::table('queue_members')->whereIn('interface', $interfaces)->delete();
    }

    /**
     * Compute the queue's member set. Two sources, queried in order:
     *
     *   1. **Skills-based shared pool** (Phase 6, primary). If the
     *      queue has required skills, every operator with an
     *      allocated softphone extension is checked against those
     *      skills. An operator who carries a required skill becomes
     *      a member with a penalty derived from their skill levels
     *      and the queue tenant's tier.
     *
     *   2. **AgentGroup fallback** (legacy). If the queue has no
     *      required skills declared, fall back to the explicit
     *      AgentGroup pivot. This keeps existing seed data and
     *      pre-skills queues working until they're migrated.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collectMembers(CallQueue $queue): array
    {
        $required = DB::table('call_queue_required_skills')
            ->where('call_queue_id', $queue->id)
            ->pluck('weight', 'skill_id');

        if ($required->isNotEmpty()) {
            return $this->collectSkillMatchedMembers($queue, $required->all());
        }

        if ($queue->agent_group_id) {
            return $this->collectAgentGroupMembers($queue);
        }

        return [];
    }

    /**
     * Skills-driven member computation. Walks every operator with
     * an active softphone extension, computes a match score from
     * the intersection of their skill levels and the queue's
     * required-skill weights, and admits them as a member when at
     * least one required skill is present.
     *
     * Penalty math (lower = ring first):
     *   - tenant tier base: enterprise 0 / pro 5 / free 10
     *   - skill match bonus: subtract up to 4 for very strong matches
     *
     * @param  array<int, int>  $required  skill_id => weight
     * @return array<int, array<string, mixed>>
     */
    protected function collectSkillMatchedMembers(CallQueue $queue, array $required): array
    {
        $tier = optional($queue->team)->tier ?? 'free';
        $basePenalty = match ($tier) {
            'enterprise' => 0,
            'pro' => 5,
            default => 10,
        };

        $operators = User::query()
            ->whereHas('extensions', fn ($q) => $q->where('is_active', true))
            ->with([
                'extensions' => fn ($q) => $q->where('is_active', true),
                'skills',
            ])
            ->get();

        $queueName = $queue->asteriskName();
        $members = [];

        foreach ($operators as $operator) {
            $extension = $operator->extensions->first();
            if ($extension === null) {
                continue;
            }

            // (skill weight × operator level) summed across overlap.
            // No overlap → score 0 → operator excluded from queue.
            $score = 0;
            foreach ($operator->skills as $skill) {
                $weight = $required[$skill->id] ?? 0;
                if ($weight > 0) {
                    $score += $weight * (int) $skill->pivot->level;
                }
            }

            if ($score === 0) {
                continue;
            }

            // Convert score to a 0–4 bonus. Anything above ~50
            // caps at 4. Subtract from the base penalty so strong
            // matches ring sooner.
            $bonus = min(4, intdiv($score, 12));
            $penalty = max(0, $basePenalty - $bonus);

            $members[] = [
                'queue_name' => $queueName,
                'interface' => 'PJSIP/'.$extension->realtimeEndpointId(),
                'membername' => $operator->name,
                'state_interface' => 'PJSIP/'.$extension->realtimeEndpointId(),
                'penalty' => $penalty,
                // Pause when the operator has manually marked
                // themselves unavailable in the topbar selector.
                // Asterisk still rings available members first
                // and only falls back to paused members if
                // nobody else answers.
                'paused' => $operator->isAvailableForWork() ? 0 : 1,
            ];
        }

        return $members;
    }

    /**
     * Legacy AgentGroup-based member collection. Kept as a fallback
     * for queues that don't declare any required skills yet.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collectAgentGroupMembers(CallQueue $queue): array
    {
        $rows = DB::table('agent_group_members')
            ->where('agent_group_id', $queue->agent_group_id)
            ->get();

        $queueName = $queue->asteriskName();
        $members = [];

        foreach ($rows as $row) {
            $extension = $this->resolveExtension($row->member_type, $row->member_id);
            if ($extension === null) {
                continue;
            }

            // Respect User-typed members' availability. Non-user
            // members (static extension pivots) default to active
            // since there's no human to flip a topbar toggle.
            $paused = 0;
            if ($row->member_type === User::class) {
                $memberUser = User::find($row->member_id);
                if ($memberUser && ! $memberUser->isAvailableForWork()) {
                    $paused = 1;
                }
            }

            $members[] = [
                'queue_name' => $queueName,
                'interface' => 'PJSIP/'.$extension->realtimeEndpointId(),
                'membername' => $extension->label ?? ('Ext '.$extension->number),
                'state_interface' => 'PJSIP/'.$extension->realtimeEndpointId(),
                'penalty' => (int) ($row->penalty ?? 0),
                'paused' => $paused,
            ];
        }

        return $members;
    }

    /**
     * Resolves the polymorphic AgentGroupMember target into the
     * Extension Asterisk should actually ring. For User members
     * we follow the user's allocated WebRTC softphone extension;
     * for Extension members we use the row directly.
     */
    protected function resolveExtension(string $memberType, int $memberId): ?Extension
    {
        if ($memberType === User::class) {
            $user = User::find($memberId);
            if ($user === null) {
                return null;
            }
            return Extension::query()
                ->where('assignable_type', User::class)
                ->where('assignable_id', $user->id)
                ->where('is_active', true)
                ->first();
        }

        if ($memberType === Extension::class) {
            return Extension::find($memberId);
        }

        return null;
    }
}
