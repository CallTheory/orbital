<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level shared directory — a phone book curated by the
 * platform operator and attached to one or more tenants. Directory
 * entries are what AI agents and live operators look up DURING call
 * handling.
 *
 * Attached to tenants via `team_shared_directory`. A tenant sees
 * directory entries from any shared directory they're subscribed
 * to alongside their own private phone book, via the global scope
 * on `DirectoryEntry`.
 */
class SharedDirectory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(DirectoryEntry::class, 'shared_directory_id');
    }

    public function fieldDefinitions(): HasMany
    {
        return $this->hasMany(DirectoryFieldDefinition::class, 'shared_directory_id')
            ->orderBy('sort_order');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_shared_directory')
            ->withPivot('is_active')
            ->withTimestamps();
    }
}
