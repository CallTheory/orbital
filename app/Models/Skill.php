<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Platform-defined operator skill.
 *
 * Skills are a single shared vocabulary across every tenant —
 * tenants don't author them, only the platform admin does. The
 * skill list is what makes a unified operator pool work: an
 * operator's "spanish" or "medical-intake" tag means the same
 * thing across every tenant queue that requires it.
 *
 * Relationships:
 *   - users(): operators who carry this skill (with level + notes)
 *   - callQueues(): queues that require this skill (with weight)
 */
class Skill extends Model
{
    public const CATEGORIES = [
        'language',
        'availability',
        'specialty',
        'tier',
        'compliance',
    ];

    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'skill_user')
            ->withPivot(['level', 'notes'])
            ->withTimestamps();
    }

    public function callQueues(): BelongsToMany
    {
        return $this->belongsToMany(CallQueue::class, 'call_queue_required_skills')
            ->withPivot(['weight'])
            ->withTimestamps();
    }
}
