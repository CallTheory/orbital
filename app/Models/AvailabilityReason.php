<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A state an operator can be in when they're logged into the
 * platform — "Available", "On break", "Lunch", "In meeting",
 * "Training", "Offline", etc. Platform-level vocabulary managed
 * by super-admins under Features → Availability Reasons.
 *
 * `available` is seeded as a regular row with `blocks_new_work =
 * false` so it lives in the same table as every other state.
 * The admin can rename it, re-color it, or edit its description
 * — but the model's `deleting` hook rejects any attempt to
 * delete it, and the Filament resource disables the blocks_new_work
 * / is_active toggles for it. Without the `available` row
 * nothing in the system can accept work, so it's protected on
 * both the UI layer and the backend as a safety net.
 *
 * `blocks_new_work` is the one field routing actually reads:
 * true means calls + emails stop being distributed to the
 * operator while this state is active; false means they still
 * get routed new work despite the visible label. Most reasons
 * are "don't ring me" states so it defaults to true; soft states
 * ("Back in 5 — still send me urgent stuff") can flip it off.
 *
 * `dot_color` is a free-form hex value picked via the admin's
 * color picker. The AvailabilitySelector renders it as an
 * inline background style on the pill dot.
 */
class AvailabilityReason extends Model
{
    use HasFactory;

    /**
     * Stable slug for the built-in "I'm taking work" row. Kept as a
     * constant so code paths (User::isAvailableForWork, the selector,
     * routing rules, future reports) have one canonical reference
     * instead of hardcoded 'available' strings, and so renaming the
     * label in the admin UI can never break the lookup.
     */
    public const AVAILABLE = 'available';

    protected static function booted(): void
    {
        // Hard-block deletion of the `available` row at the model
        // layer. The Filament resource hides the delete action for
        // this row, but that's only a UI-level guard — a bulk action,
        // a tinker session, or a future API call could still try.
        // Without the `available` row the system has no "accepting
        // work" state and every operator is stranded, so we refuse
        // the operation at the last line of defense.
        static::deleting(function (self $reason): bool {
            if ($reason->slug === self::AVAILABLE) {
                return false;
            }
            return true;
        });
    }

    protected $fillable = [
        'slug',
        'label',
        'description',
        'dot_color',
        'blocks_new_work',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'blocks_new_work' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
