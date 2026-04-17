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
 * `blocks_voice` and `blocks_non_voice` control which channels
 * stop routing when this state is active. Voice = phone calls
 * via Asterisk queues. Non-voice = email, SMS, chat. An operator
 * on "Non-Voice Only" has blocks_voice=true, blocks_non_voice=false
 * so they can work email but won't get phone calls. Most reasons
 * block both channels by default.
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
        'blocks_voice',
        'blocks_non_voice',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'blocks_voice' => 'boolean',
            'blocks_non_voice' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Convenience: does this reason block all work?
     */
    public function blocksAllWork(): bool
    {
        return $this->blocks_voice && $this->blocks_non_voice;
    }
}
