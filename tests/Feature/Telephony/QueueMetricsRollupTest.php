<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 5 verification — the roll-up command turns raw queue_log
 * events into one daily aggregate row per queue. Asterisk writes
 * the queue_log rows itself in production via the realtime backend
 * (logger.conf), but the command's logic is pure DB → DB and we
 * test it with hand-inserted fixture rows.
 *
 * The aggregation math we lock in here:
 *   - ENTERQUEUE   → calls_offered++
 *   - CONNECT      → calls_answered++, total_wait += data1
 *   - ABANDON      → calls_abandoned++, total_wait += data3
 *   - COMPLETE*    → total_talk += data2
 *   - avg_wait     → total_wait / (answered + abandoned)
 *   - avg_talk     → total_talk / answered
 */
class QueueMetricsRollupTest extends TestCase
{
    use RefreshDatabase;

    public function test_roll_up_aggregates_queue_log_events_into_daily_row(): void
    {
        $date = Carbon::parse('2026-04-13');
        $queueName = 't42_support';

        // Fixture: 4 calls offered, 3 answered, 1 abandoned.
        $events = [
            // call 1: offered, answered after 5s wait, talked 60s
            ['ENTERQUEUE', null, null, null],
            ['CONNECT', '5', null, null],
            ['COMPLETEAGENT', null, '60', null],

            // call 2: offered, answered after 10s wait, talked 90s
            ['ENTERQUEUE', null, null, null],
            ['CONNECT', '10', null, null],
            ['COMPLETECALLER', null, '90', null],

            // call 3: offered, answered after 15s wait, talked 30s
            ['ENTERQUEUE', null, null, null],
            ['CONNECT', '15', null, null],
            ['COMPLETEAGENT', null, '30', null],

            // call 4: offered, abandoned after 20s wait
            ['ENTERQUEUE', null, null, null],
            ['ABANDON', null, null, '20'],
        ];

        foreach ($events as $i => [$event, $d1, $d2, $d3]) {
            DB::table('queue_log')->insert([
                'time' => $date->copy()->addSeconds($i),
                'callid' => 'call-'.$i,
                'queuename' => $queueName,
                'agent' => 'test',
                'event' => $event,
                'data1' => $d1,
                'data2' => $d2,
                'data3' => $d3,
            ]);
        }

        $this->artisan('orbital:roll-up-queue-metrics', ['--date' => '2026-04-13'])
            ->assertExitCode(0);

        $row = DB::table('queue_metrics_daily')
            ->where('queue_name', $queueName)
            ->where('date', '2026-04-13')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(4, (int) $row->calls_offered);
        $this->assertSame(3, (int) $row->calls_answered);
        $this->assertSame(1, (int) $row->calls_abandoned);
        $this->assertSame(50, (int) $row->total_wait_seconds); // 5+10+15+20
        $this->assertSame(180, (int) $row->total_talk_seconds); // 60+90+30
        $this->assertSame(12, (int) $row->avg_wait_seconds);    // 50/4
        $this->assertSame(60, (int) $row->avg_talk_seconds);    // 180/3
        $this->assertSame(20, (int) $row->max_wait_seconds);    // longest
        $this->assertSame(42, (int) $row->team_id);             // recovered from prefix
    }

    public function test_roll_up_is_idempotent(): void
    {
        $date = Carbon::parse('2026-04-13');
        DB::table('queue_log')->insert([
            'time' => $date->copy()->addSeconds(5),
            'callid' => 'c1',
            'queuename' => 't1_demo',
            'agent' => 'x',
            'event' => 'ENTERQUEUE',
        ]);

        $this->artisan('orbital:roll-up-queue-metrics', ['--date' => '2026-04-13']);
        $this->artisan('orbital:roll-up-queue-metrics', ['--date' => '2026-04-13']);

        $count = DB::table('queue_metrics_daily')
            ->where('queue_name', 't1_demo')
            ->where('date', '2026-04-13')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_roll_up_prunes_queue_log_rows_older_than_keep_days(): void
    {
        // 100 days old — outside the default 90-day window
        DB::table('queue_log')->insert([
            'time' => now()->subDays(100),
            'callid' => 'old',
            'queuename' => 't1_old',
            'agent' => 'x',
            'event' => 'ENTERQUEUE',
        ]);

        // Recent — should survive
        DB::table('queue_log')->insert([
            'time' => now()->subDays(5),
            'callid' => 'new',
            'queuename' => 't1_new',
            'agent' => 'x',
            'event' => 'ENTERQUEUE',
        ]);

        $this->artisan('orbital:roll-up-queue-metrics', [
            '--date' => now()->subDays(5)->toDateString(),
        ])->assertExitCode(0);

        $this->assertSame(0, DB::table('queue_log')->where('callid', 'old')->count());
        $this->assertSame(1, DB::table('queue_log')->where('callid', 'new')->count());
    }

    public function test_platform_queue_with_no_team_prefix_has_null_team_id(): void
    {
        $date = Carbon::parse('2026-04-13');
        DB::table('queue_log')->insert([
            'time' => $date,
            'callid' => 'c1',
            'queuename' => 'platform_pool',
            'agent' => 'x',
            'event' => 'ENTERQUEUE',
        ]);

        $this->artisan('orbital:roll-up-queue-metrics', ['--date' => '2026-04-13']);

        $row = DB::table('queue_metrics_daily')->where('queue_name', 'platform_pool')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->team_id);
    }
}
