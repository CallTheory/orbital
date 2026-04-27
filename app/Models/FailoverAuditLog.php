<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record for a single Failover Central action. See the
 * migration for column semantics. Create via the static helper
 * `record()` so callers can't accidentally skip the required
 * fields.
 */
class FailoverAuditLog extends Model
{
    protected $fillable = [
        'occurred_at',
        'actor_user_id',
        'tier',
        'action',
        'target',
        'success',
        'output',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'success' => 'boolean',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Write one audit row. Truncates output to keep the DB honest.
     *
     * @param  string  $tier  e.g. "postgres", "valkey", "kamailio"
     * @param  string  $action  e.g. "switchover", "drain", "activate"
     * @param  string|null  $target  node/server URI; null for cluster-wide
     */
    public static function record(
        string $tier,
        string $action,
        ?string $target,
        bool $success,
        ?string $output = null,
    ): self {
        return self::create([
            'occurred_at' => now(),
            'actor_user_id' => auth()->id(),
            'tier' => $tier,
            'action' => $action,
            'target' => $target,
            'success' => $success,
            'output' => $output !== null ? mb_substr($output, 0, 8000) : null,
        ]);
    }
}
