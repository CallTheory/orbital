<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per "mute this component" acknowledgment, doubling
 * as the audit log entry for the ack + later clear.
 *
 * Active acks have `cleared_at IS NULL`. Queried by the
 * SystemHealthService on every runAll() pass to decide which
 * components should be rolled up as OK in the aggregate even
 * when their raw status is warn/down.
 *
 * Clearing:
 *   - Manual: operator clicks "Clear" on the card → cleared_at
 *     set + cleared_by_user_id set to them
 *   - Auto: SystemHealthService detects the underlying check
 *     has returned to OK → cleared_at set + cleared_by_user_id
 *     left null, so the audit log distinguishes the two paths
 */
class HealthCheckAcknowledgment extends Model
{
    use HasFactory;

    protected $fillable = [
        'check_key',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'reason',
        'cleared_at',
        'cleared_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    /**
     * Currently-active acks — ones that haven't been cleared
     * yet (manual or auto). Used by SystemHealthService.
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('cleared_at');
    }

    /**
     * Return the set of check keys that have an active ack
     * right now, as a plain array of strings. Cheap enough to
     * call on every health-check run because the table is
     * small and indexed on (check_key, cleared_at).
     *
     * @return array<int, string>
     */
    public static function activeCheckKeys(): array
    {
        return self::active()->pluck('check_key')->unique()->values()->all();
    }

    /**
     * Fetch the currently-active ack for a given check key, or
     * null if the key isn't acked. Used by the dashboard card
     * to display "Acknowledged by {name} at {time}".
     */
    public static function activeFor(string $checkKey): ?self
    {
        return self::active()
            ->where('check_key', $checkKey)
            ->latest('acknowledged_at')
            ->first();
    }
}
