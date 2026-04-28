<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

use App\Models\CallQueue;
use Illuminate\Support\Facades\DB;

/**
 * Translates a {@see CallQueue} into a row in the ARA `queues`
 * table, keyed by the Asterisk-side queue name (e.g. `t42_support`
 * — see {@see CallQueue::asteriskName()} for the prefix scheme that
 * makes per-client queue names collision-safe).
 *
 * The matching `queue_members` rows are managed separately by
 * {@see QueueMemberSyncer}, which computes operator membership from
 * skills + client tier + per-queue required skills.
 *
 * Idempotent. The fields we sync here are the ones our domain
 * model actually carries — strategy, timeouts, capacity, music on
 * hold, etc. Asterisk has many more queue knobs (announcements,
 * service level, periodic, etc.) that are at their defaults until
 * we surface them in the Filament UI.
 */
class QueueSyncer
{
    public function sync(CallQueue $queue): void
    {
        // Strategy + ring timings come from the agent group's
        // platform-curated template (one strategy per agent pool).
        // Wrapup is a per-queue tuning knob — sales calls and FAQ
        // calls might share the same agents but need different post-
        // call recovery, so it lives directly on the queue row.
        $template = $queue->agentGroup?->strategyTemplate;

        $row = [
            'name' => $queue->asteriskName(),
            'strategy' => $template?->strategy ?: 'ringall',
            'timeout' => (int) ($template?->timeout ?? 30),
            'retry' => (int) ($template?->retry ?? 5),
            'wrapuptime' => (int) ($queue->wrapup_time ?? 0),
            'maxlen' => 0,
            'musiconhold' => $queue->music_on_hold ?: 'default',
            'joinempty' => 1,
            'leavewhenempty' => 1,
            'monitor_type' => 'MixMonitor',
        ];

        $this->upsert($row);
    }

    public function delete(CallQueue $queue): void
    {
        DB::transaction(function () use ($queue) {
            $name = $queue->asteriskName();
            DB::table('queue_members')->where('queue_name', $name)->delete();
            DB::table('queues')->where('name', $name)->delete();
        });
    }

    protected function upsert(array $row): void
    {
        $name = $row['name'];
        $existing = DB::table('queues')->where('name', $name)->exists();
        if ($existing) {
            DB::table('queues')->where('name', $name)->update($row);
        } else {
            DB::table('queues')->insert($row);
        }
    }
}
