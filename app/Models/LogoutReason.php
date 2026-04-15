<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reason an operator picks when signing out of the operator
 * panel — "End of shift", "Going home", "Taking a break", etc.
 * Platform-level vocabulary managed by super-admins under
 * Features → Logout Reasons.
 *
 * Distinct from AvailabilityReason: availability reasons cover
 * "I'm still logged in but not taking new work right now", while
 * logout reasons only get recorded at the moment the session
 * actually ends. No routing flag, no color — just a label.
 */
class LogoutReason extends Model
{
    use HasFactory;

    protected $fillable = [
        'label',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
