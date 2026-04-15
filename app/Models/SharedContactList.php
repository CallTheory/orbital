<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level shared contact list.
 *
 * Created and managed by super-admins under
 * `/admin/shared-contact-lists`, attached to tenants via the
 * `team_shared_contact_list` pivot. Once a tenant is attached,
 * the global scope on `Contact` unions that tenant's private
 * rows with the rows on the shared lists they're subscribed to,
 * so downstream lookups (AI caller lookup, operator search)
 * return the unified set automatically.
 *
 * A shared list brings its own `contact_field_definitions`
 * (rows with `shared_contact_list_id` set and `team_id` null),
 * so each list can have its own schema without colliding with
 * tenant-private definitions.
 *
 * Deliberately does NOT use BelongsToTeam — this is a platform
 * entity, not a tenant-scoped one. Super-admin surfaces query
 * it directly.
 */
class SharedContactList extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * All contact rows that belong to this shared list. Queried
     * via `Contact::withoutGlobalScope('team')->where('shared_contact_list_id', ...)`
     * because the global scope on Contact doesn't know to include
     * shared rows when the caller is a super-admin (they use the
     * admin management UI, which bypasses the scope).
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'shared_contact_list_id');
    }

    /**
     * Field definitions for this list's schema. Separate from
     * the tenant-scoped definitions — each shared list owns its
     * own set, keyed off `shared_contact_list_id`.
     */
    public function fieldDefinitions(): HasMany
    {
        return $this->hasMany(ContactFieldDefinition::class, 'shared_contact_list_id')
            ->orderBy('sort_order');
    }

    /**
     * Tenants attached to this shared list. Attachment is
     * managed by super-admins from the admin UI; `is_active`
     * on the pivot lets them temporarily detach without
     * deleting the link.
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_shared_contact_list')
            ->withPivot('is_active')
            ->withTimestamps();
    }
}
