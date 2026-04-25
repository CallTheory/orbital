<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level call-queue strategy template.
 *
 * Curated by the platform operator; clients pick one from a
 * dropdown when they create a CallQueue. This lets a small ops
 * team keep call-handling behavior consistent across hundreds of
 * accounts without per-client hand-tuning.
 */
class QueueStrategyTemplate extends Model
{
    public const STRATEGY_RINGALL = 'ringall';

    public const STRATEGY_ROUNDROBIN = 'roundrobin';

    public const STRATEGY_LEASTRECENT = 'leastrecent';

    public const STRATEGY_RANDOM = 'random';

    public const STRATEGY_FEWESTCALLS = 'fewestcalls';

    public const STRATEGIES = [
        self::STRATEGY_RINGALL,
        self::STRATEGY_ROUNDROBIN,
        self::STRATEGY_LEASTRECENT,
        self::STRATEGY_RANDOM,
        self::STRATEGY_FEWESTCALLS,
    ];

    protected $fillable = [
        'name',
        'description',
        'strategy',
        'timeout',
        'retry',
        'wrapup_time',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'timeout' => 'integer',
            'retry' => 'integer',
            'wrapup_time' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function callQueues(): HasMany
    {
        return $this->hasMany(CallQueue::class, 'strategy_template_id');
    }
}
