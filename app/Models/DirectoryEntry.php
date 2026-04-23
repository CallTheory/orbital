<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeamOrSharedPool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The client's own phone book. AI agents and live operators consult
 * the directory while handling a call — "transfer to Dr. Smith's
 * office", "Alice prefers SMS after hours". Never logs in to Orbital.
 *
 * Fully client-defined schema. Every field value lives inside the
 * `values` JSONB column keyed by the slug of a
 * DirectoryFieldDefinition row. Role accessors ({@see name()},
 * {@see email()}, {@see phone()}, {@see organization()}) resolve the
 * canonical field at read time so downstream code doesn't need to
 * know what the client named their fields.
 */
class DirectoryEntry extends Model
{
    use BelongsToTeamOrSharedPool;
    use SoftDeletes;

    public const SHARED_PARENT_COLUMN = 'shared_directory_id';
    public const TEAM_SHARED_PIVOT_TABLE = 'team_shared_directory';

    protected $fillable = [
        'team_id',
        'shared_directory_id',
        'values',
    ];

    /** @var array<string, ?DirectoryFieldDefinition> */
    protected array $resolvedRoleFields = [];

    protected function casts(): array
    {
        return [
            'values' => 'array',
        ];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            DirectoryTag::class,
            'directory_entry_directory_tag',
            'directory_entry_id',
            'directory_tag_id',
        );
    }

    public function value(string $key, mixed $default = null): mixed
    {
        $values = $this->values ?? [];
        return $values[$key] ?? $default;
    }

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
     * Display-ready name, falling back to "Unnamed" when the client
     * hasn't assigned a role=name field yet or the field is empty.
     * Kept as a method (not an accessor) so callers can distinguish
     * "no value" from "we rendered a placeholder".
     */
    public function fullName(): string
    {
        return $this->name() ?? 'Unnamed';
    }

    protected function valueForRole(string $role): ?string
    {
        $definition = $this->resolveFieldByRole($role);
        if ($definition === null) {
            return null;
        }

        $raw = $this->value($definition->key);
        return $raw === null || $raw === '' ? null : (string) $raw;
    }

    protected function resolveFieldByRole(string $role): ?DirectoryFieldDefinition
    {
        if (array_key_exists($role, $this->resolvedRoleFields)) {
            return $this->resolvedRoleFields[$role];
        }

        // Look up the row's OWN parent's definitions, bypassing
        // the unified client-view scope.
        $query = DirectoryFieldDefinition::withoutGlobalScope('team')
            ->where('role', $role)
            ->where('is_active', true);

        if ($this->shared_directory_id !== null) {
            $query->where('shared_directory_id', $this->shared_directory_id)
                  ->whereNull('team_id');
        } else {
            $query->where('team_id', $this->team_id)
                  ->whereNull('shared_directory_id');
        }

        $this->resolvedRoleFields[$role] = $query->first();

        return $this->resolvedRoleFields[$role];
    }
}
