<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CallQueue;
use App\Models\QueueStrategyTemplate;
use Illuminate\Database\Seeder;

/**
 * Sensible defaults every platform ships with. Operators can add
 * more via the Filament admin; these cover 80% of use cases and
 * give the migration backfill something to match against.
 */
class QueueStrategyTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Ring All 30s',
                'description' => 'Rings every available agent simultaneously for 30 seconds before moving to overflow. Default for most clients.',
                'strategy' => QueueStrategyTemplate::STRATEGY_RINGALL,
                'timeout' => 30,
                'retry' => 5,
                'wrapup_time' => 0,
                'is_default' => true,
            ],
            [
                'name' => 'Least Recent 45s',
                'description' => 'Rings whichever agent has been idle longest. 45 second ring timeout.',
                'strategy' => QueueStrategyTemplate::STRATEGY_LEASTRECENT,
                'timeout' => 45,
                'retry' => 5,
                'wrapup_time' => 5,
                'is_default' => false,
            ],
            [
                'name' => 'Round Robin 20s',
                'description' => 'Rotates through agents in a fixed order, 20s per agent. Good for even-load call centers.',
                'strategy' => QueueStrategyTemplate::STRATEGY_ROUNDROBIN,
                'timeout' => 20,
                'retry' => 3,
                'wrapup_time' => 0,
                'is_default' => false,
            ],
            [
                'name' => 'Fast AI Fallback 15s',
                'description' => 'Short 15s ring, then straight to the overflow AI persona. Minimises caller wait.',
                'strategy' => QueueStrategyTemplate::STRATEGY_RINGALL,
                'timeout' => 15,
                'retry' => 2,
                'wrapup_time' => 0,
                'is_default' => false,
            ],
        ];

        foreach ($templates as $row) {
            QueueStrategyTemplate::updateOrCreate(
                ['name' => $row['name']],
                $row,
            );
        }

        // Backfill existing call queues. Match by (strategy, timeout,
        // retry, wrapup_time); fall back to the default template if
        // no exact match. Harmless to re-run — rows already pointing
        // at a template are left alone.
        $all = QueueStrategyTemplate::all();
        $default = $all->firstWhere('is_default', true) ?? $all->first();

        CallQueue::withoutGlobalScope('team')
            ->whereNull('strategy_template_id')
            ->get()
            ->each(function (CallQueue $q) use ($all, $default) {
                $match = $all->first(fn ($t) => $t->strategy === $q->strategy
                    && $t->timeout === (int) $q->timeout
                    && $t->retry === (int) $q->retry
                    && $t->wrapup_time === (int) $q->wrapup_time);
                $q->forceFill(['strategy_template_id' => ($match ?? $default)->id])->save();
            });
    }
}
