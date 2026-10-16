<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallSessionState;
use App\Models\ClientSlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the AI collected on a handed-off call, shaped for the receiving
 * operator's system. `fields` has one entry per client slot (null when
 * not captured); captured keys with no slot land in `other_fields`.
 *
 * @mixin CallSessionState
 */
class HandoffResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $captured = $this->fields ?? [];

        $slots = ClientSlot::query()
            ->withoutGlobalScope('team')
            ->where('team_id', $this->team_id)
            ->orderBy('id')
            ->get();

        $fields = $slots->map(fn (ClientSlot $slot): array => [
            'key' => $slot->name,
            'label' => $slot->displayLabel(),
            'type' => $slot->type,
            'value' => $captured[$slot->name] ?? null,
        ])->values()->all();

        $other = array_diff_key($captured, $slots->pluck('name')->flip()->all());

        return [
            'id' => $this->id,
            'caller_number' => $this->caller_number,
            'called_number' => $this->called_number,
            'handoff_number' => $this->handoff_number,
            'handed_off_at' => $this->handed_off_at?->toIso8601String(),
            'started_at' => $this->created_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'fields' => $fields,
            'other_fields' => (object) $other,
        ];
    }
}
