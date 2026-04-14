<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

use App\Models\CallQueue;
use Illuminate\Support\Facades\DB;

/**
 * Translates a {@see CallQueue} into a row in the ARA `queues`
 * table, keyed by the Asterisk-side queue name (e.g. `t42_support`
 * — see {@see CallQueue::asteriskName()} for the prefix scheme that
 * makes per-tenant queue names collision-safe).
 *
 * The matching `queue_members` rows are managed separately by
 * {@see QueueMemberSyncer}, which computes operator membership from
 * skills + tenant tier + per-queue required skills.
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
        $row = [
            'name' => $queue->asteriskName(),
            'strategy' => $queue->strategy ?: 'ringall',
            'timeout' => (int) ($queue->timeout ?: 30),
            'retry' => (int) ($queue->retry ?: 5),
            'wrapuptime' => (int) ($queue->wrapup_time ?: 10),
            'maxlen' => (int) ($queue->max_callers ?: 0),
            'musiconhold' => $queue->music_on_hold ?: 'default',
            'joinempty' => $queue->join_empty ? 1 : 0,
            'leavewhenempty' => $queue->leave_when_empty ? 1 : 0,
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
