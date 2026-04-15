<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeamOrSharedPool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Account-level tenant contact. People the platform operator reaches
 * out to about the tenant's account (billing, holiday cards, technical
 * escalations, newsletter). Distinct from DirectoryEntry, which is the
 * tenant's own phone book used during call handling.
 *
 * Fully tenant-defined schema — every field value lives inside the
 * `values` JSONB column, keyed by the slug of a ContactFieldDefinition
 * row. Read values via the {@see value()} helper, or via the role
 * shortcuts ({@see name()}, {@see email()}, {@see phone()},
 * {@see organization()}) which look up the canonical field for a
 * given semantic role on the fly.
 *
 * Most contacts never log in. When `user_id` is set, the contact also
 * has a User row and can sign in to the portal. Removing the user
 * leaves the contact row intact (historical record of who was a
 * portal user stays useful).
 */
class Contact extends Model
{
    use BelongsToTeamOrSharedPool;
    use SoftDeletes;

    /**
     * Column that points at the platform-level container when a
     * row is shared. Read by the BelongsToTeamOrSharedPool trait
     * to union shared rows into tenant-scoped queries.
     */
    public const SHARED_PARENT_COLUMN = 'shared_contact_list_id';

    /**
     * Pivot table linking tenants to the shared contact lists
     * they're subscribed to. Read by the trait's global scope
     * to resolve which shared rows a tenant can see.
     */
    public const TEAM_SHARED_PIVOT_TABLE = 'team_shared_contact_list';

    protected $fillable = [
        'team_id',
        'shared_contact_list_id',
        'user_id',
        'values',
    ];

    /**
     * Per-instance cache of "which field definition plays role X"
     * lookups. Prevents a definitions query per role per row when a
     * list page renders fifty rows with five accessor calls each.
     *
     * @var array<string, ?ContactFieldDefinition>
     */
    protected array $resolvedRoleFields = [];

    protected function casts(): array
    {
        return [
            'values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ContactTag::class, 'contact_contact_tag');
    }

    public function hasPortalAccess(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Fetch a custom field value by its definition key.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        $values = $this->values ?? [];
        return $values[$key] ?? $default;
    }

    /**
     * The canonical display name for this contact — the value of the
     * field whose definition has role=name. Null if the tenant hasn't
     * assigned the name role to any field yet.
     */
    public function name(): ?string
    {
        return $this->valueForRole('name');
    }

    public function email(): ?string
    {
        return $this->valueForRole('email');
    }

    public function phone(): ?string
    {
        return $this->valueForRole('phone');
    }

    public function organization(): ?string
    {
        return $this->valueForRole('organization');
    }

    /**
     * Resolves the field definition with the given role on this
     * contact's team and returns the matching stored value. Returns
     * null if no definition is assigned to the role, if the field is
     * inactive, or if no value is stored on this row.
     */
    protected function valueForRole(string $role): ?string
    {
        $definition = $this->resolveFieldByRole($role);
        if ($definition === null) {
            return null;
        }

        $raw = $this->value($definition->key);
        return $raw === null || $raw === '' ? null : (string) $raw;
    }

    protected function resolveFieldByRole(string $role): ?ContactFieldDefinition
    {
        if (array_key_exists($role, $this->resolvedRoleFields)) {
            return $this->resolvedRoleFields[$role];
        }

        // Parent-scoped lookup: tenant-private contacts use the
        // tenant's own field definitions; shared contacts use
        // their shared list's own definitions. The resolver is
        // scoped to whichever parent the row has.
        //
        // Bypassing the global scope because the definitions
        // table ALSO uses BelongsToTeamOrSharedPool and would
        // otherwise OR in shared definitions via the pivot
        // lookup — we want a strict parent-owned lookup here,
        // not the unified tenant-view.
        $query = ContactFieldDefinition::withoutGlobalScope('team')
            ->where('role', $role)
            ->where('is_active', true);

        if ($this->shared_contact_list_id !== null) {
            $query->where('shared_contact_list_id', $this->shared_contact_list_id)
                  ->whereNull('team_id');
        } else {
            $query->where('team_id', $this->team_id)
                  ->whereNull('shared_contact_list_id');
        }

        $this->resolvedRoleFields[$role] = $query->first();

        return $this->resolvedRoleFields[$role];
    }
}
