<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentGroup;
use App\Models\QueueStrategyTemplate;
use Illuminate\Database\Seeder;

/**
 * Sensible defaults every platform ships with. Operators can add more
 * via the Filament admin; these cover 80% of use cases.
 *
 * Strategy now lives on `agent_groups`, not on individual call queues
 * — the seeder backfills any group missing a template with the
 * platform default after creating the templates.
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
                'is_default' => true,
            ],
            [
                'name' => 'Least Recent 45s',
                'description' => 'Rings whichever agent has been idle longest. 45 second ring timeout.',
                'strategy' => QueueStrategyTemplate::STRATEGY_LEASTRECENT,
                'timeout' => 45,
                'retry' => 5,
                'is_default' => false,
            ],
            [
                'name' => 'Round Robin 20s',
                'description' => 'Rotates through agents in a fixed order, 20s per agent. Good for even-load call centers.',
                'strategy' => QueueStrategyTemplate::STRATEGY_ROUNDROBIN,
                'timeout' => 20,
                'retry' => 3,
                'is_default' => false,
            ],
            [
                'name' => 'Fast AI Fallback 15s',
                'description' => 'Short 15s ring, then straight to the overflow AI persona. Minimises caller wait.',
                'strategy' => QueueStrategyTemplate::STRATEGY_RINGALL,
                'timeout' => 15,
                'retry' => 2,
                'is_default' => false,
            ],
        ];

        foreach ($templates as $row) {
            QueueStrategyTemplate::updateOrCreate(
                ['name' => $row['name']],
                $row,
            );
        }

        // Catch-up backfill for groups created before templates existed
        // (the schema migration runs before this seeder on a fresh
        // install, so its initial backfill finds an empty templates
        // table and leaves agent_groups.strategy_template_id null).
        $defaultId = QueueStrategyTemplate::where('is_default', true)->value('id');
        if ($defaultId !== null) {
            AgentGroup::query()
                ->whereNull('strategy_template_id')
                ->update(['strategy_template_id' => $defaultId]);
        }
    }
}
