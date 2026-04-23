<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remap any flow_steps that reference team-scoped intake goals to the
 * closest platform-library goal by `key`, stash the team-goal's
 * knowledge_store_ids into the flow_step's step_overrides (so the
 * attachment isn't lost), then delete the team-scoped goal rows.
 *
 * Sets up the schema cleanup that follows: once no team-scoped goals
 * exist, the next migration drops `team_id` and `template_id` from
 * `intake_goals` entirely — the library becomes the single catalog.
 *
 * Library matching is by key. A team-scoped `answer_questions` goal
 * doesn't match any library key exactly, so we fall back to a manual
 * alias map below. If no match is found for a given team goal, the
 * migration aborts loudly — better than silently orphaning a step.
 */
return new class extends Migration
{
    /**
     * Manual aliases for team-goal keys that don't have an identically-
     * named library goal. Maps team-scoped goal key → library goal key.
     */
    private array $keyAliases = [
        'answer_questions' => 'answer_from_faq',
        'overflow_take_message' => 'take_message',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('intake_goals') || ! Schema::hasColumn('intake_goals', 'team_id')) {
            return;
        }

        $library = DB::table('intake_goals')
            ->whereNull('team_id')
            ->whereNull('template_id')
            ->pluck('id', 'key');

        $teamGoals = DB::table('intake_goals')->whereNotNull('team_id')->get();

        foreach ($teamGoals as $goal) {
            $libraryKey = $this->keyAliases[$goal->key] ?? $goal->key;
            $libraryId = $library[$libraryKey] ?? null;

            if ($libraryId === null) {
                throw new \RuntimeException(
                    "No library goal found for team-scoped goal id={$goal->id} (key={$goal->key}). "
                    ."Add an alias to the migration's \$keyAliases map before re-running."
                );
            }

            $steps = DB::table('intake_flow_steps')->where('intake_goal_id', $goal->id)->get();
            foreach ($steps as $step) {
                $overrides = $step->step_overrides ? json_decode($step->step_overrides, true) : [];
                if (! empty($goal->knowledge_store_ids)) {
                    $decoded = json_decode($goal->knowledge_store_ids, true);
                    if (! empty($decoded)) {
                        $overrides['knowledge_store_ids'] = $decoded;
                    }
                }

                DB::table('intake_flow_steps')
                    ->where('id', $step->id)
                    ->update([
                        'intake_goal_id' => $libraryId,
                        'step_overrides' => $overrides === [] ? null : json_encode($overrides),
                    ]);
            }

            DB::table('intake_goals')->where('id', $goal->id)->delete();
        }
    }

    public function down(): void
    {
        // Irreversible — the team-scoped rows are gone. Re-seeding
        // the demo data recreates them in a different shape.
    }
};
