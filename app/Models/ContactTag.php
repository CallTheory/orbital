<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tag in the contact library. Either platform-seeded (team_id null —
 * visible to every tenant) or tenant-authored (team_id set). The
 * BelongsToTeam trait still applies for tenant-scoped queries; to
 * include the platform defaults in a list use the PlatformLibrary
 * scope convention we use elsewhere (see IntakeGoal).
 */
class ContactTag extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'color',
        'description',
    ];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_contact_tag');
    }
}
