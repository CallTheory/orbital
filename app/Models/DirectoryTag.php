<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tag on a directory entry. Used for call-time routing decisions
 * (after-hours, emergency, spanish-speaker, department-sales).
 */
class DirectoryTag extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'color',
        'description',
    ];

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(
            DirectoryEntry::class,
            'directory_entry_directory_tag',
            'directory_tag_id',
            'directory_entry_id',
        );
    }
}
