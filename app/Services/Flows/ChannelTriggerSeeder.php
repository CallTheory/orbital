<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\IntakeFlow;
use App\Models\Orchestration;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Bootstraps a client's orchestration infrastructure and ensures
 * the five channel-trigger flows exist on the canvas.
 *
 * Two responsibilities:
 *   1. `ensureBootstrap(Team)` — make sure the team has at least
 *      one Orchestration ("Default"). Returns it.
 *   2. `ensureTriggersFor(Team)` — run the bootstrap, then plant
 *      the five channel-trigger flows in the Default orchestration
 *      (inbound phone, email, sms, wctp, outbound phone), laid out
 *      across the top row. Triggers start inactive so they don't
 *      fire anything until the author wires an outbound edge.
 *
 * Idempotent. Safe to call from the controller (lazy backfill on
 * editor open) and from seeders.
 */
class ChannelTriggerSeeder
{
    private const CHANNELS = [
        IntakeFlow::TRIGGER_INBOUND_PHONE => ['Inbound Phone', 40, 40],
        IntakeFlow::TRIGGER_INBOUND_EMAIL => ['Inbound Email', 340, 40],
        IntakeFlow::TRIGGER_INBOUND_SMS => ['Inbound SMS', 640, 40],
        IntakeFlow::TRIGGER_INBOUND_WCTP => ['Inbound WCTP', 940, 40],
        IntakeFlow::TRIGGER_OUTBOUND_PHONE => ['Outbound Phone', 1240, 40],
    ];

    /**
     * Ensure the team has a Default Orchestration. Returns it.
     */
    public function ensureBootstrap(Team $team): Orchestration
    {
        return DB::transaction(function () use ($team) {
            $orchestration = Orchestration::query()
                ->where('team_id', $team->id)
                ->orderBy('id')
                ->first();

            if (! $orchestration) {
                $orchestration = Orchestration::create([
                    'team_id' => $team->id,
                    'name' => 'Default',
                    'description' => 'Default orchestration for this client.',
                ]);
            }

            return $orchestration;
        });
    }

    /**
     * Create any missing trigger flows for the team's Default
     * orchestration. Existing trigger flows are left untouched.
     * Returns the set of flow IDs that were created.
     *
     * @return array<int, int>
     */
    public function ensureTriggersFor(Team $team): array
    {
        $orchestration = $this->ensureBootstrap($team);

        $existing = IntakeFlow::query()
            ->where('orchestration_id', $orchestration->id)
            ->whereIn('trigger_type', array_keys(self::CHANNELS))
            ->pluck('trigger_type')
            ->all();

        $created = [];
        $order = 0;

        DB::transaction(function () use ($team, $orchestration, $existing, &$created, &$order) {
            foreach (self::CHANNELS as $triggerType => $meta) {
                $order++;
                if (in_array($triggerType, $existing, true)) {
                    continue;
                }

                [$name, $x, $y] = $meta;

                $flow = IntakeFlow::create([
                    'team_id' => $team->id,
                    'orchestration_id' => $orchestration->id,
                    'name' => $name,
                    'description' => null,
                    'trigger_type' => $triggerType,
                    'kind' => IntakeFlow::KIND_CALL_FLOW,
                    'is_active' => false,
                    'display_order' => $order,
                    'canvas_x' => $x,
                    'canvas_y' => $y,
                ]);

                $created[] = $flow->id;
            }
        });

        return $created;
    }
}
