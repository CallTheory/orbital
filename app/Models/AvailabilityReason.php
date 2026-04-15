<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reason an operator is not on "Available" — "On break",
 * "Lunch", "In meeting", "Training", "Offline", etc. Platform-
 * level vocabulary managed by super-admins under Features →
 * Availability Reasons.
 *
 * The built-in `available` state isn't represented here — it's
 * the implicit "taking work" fallback that always sits at the
 * top of the selector dropdown. Everything in this table is
 * some flavor of "not Available".
 *
 * `blocks_new_work` is the one field routing actually reads:
 * true means calls + emails stop being distributed to the
 * operator while this reason is active; false means they
 * still get routed new work despite not technically being on
 * Available. The default is true — most reasons are "don't
 * ring me" statuses.
 *
 * `dot_color` is a free-form hex value picked via the admin's
 * color picker. The AvailabilitySelector renders it as an
 * inline background style on the pill dot.
 */
class AvailabilityReason extends Model
{
    use HasFactory;

    /** The built-in `available` slug, used as a sentinel value. */
    public const AVAILABLE = 'available';

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
