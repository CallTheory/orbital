<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\DirectoryFieldDefinition;
use Illuminate\Support\Collection;

/**
 * Header → client-defined field matcher for the CSV / Excel import
 * flow. The Filament import action calls this twice per file:
 *
 *   1. {@see optionsForDirectory()} to build the dropdown list of
 *      valid mapping targets — sourced entirely from the client's
 *      own directory field definitions.
 *
 *   2. {@see guessDirectory()} once per column header to seed each
 *      Select's default value with a best-guess match. The matcher
 *      uses two strategies: first an exact normalize-and-compare
 *      against every definition's label and key, then a role-based
 *      fallback so common header names ("Email Address", "Phone")
 *      still find the right field even when the client labeled it
 *      something idiosyncratic ("Patient Email", "Office Number").
 */
class ColumnMappingGuesser
{
    /**
     * Header aliases that should match a definition with a given role.
     * Used as a fallback after exact normalize-and-compare misses.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_ALIASES = [
        'name' => ['name', 'fullname', 'contact', 'contactname', 'firstlast', 'patient', 'client'],
        'email' => ['email', 'emailaddress', 'mail', 'emailaddr', 'emailcontact'],
        'phone' => ['phone', 'phonenumber', 'tel', 'telephone', 'phoneno', 'mobilephone', 'cell', 'cellphone', 'mobile', 'primaryphone', 'workphone', 'officephone'],
        'organization' => ['organization', 'organisation', 'company', 'employer', 'org', 'business'],
    ];

    /**
     * Mapping options for the Directory import dropdown.
     *
     * @return array<string, string>  ['' => '— Skip —', 'field_key' => 'Field label', …]
     */
    public function optionsForDirectory(int $teamId): array
    {
        return $this->buildOptions($this->directoryDefinitions($teamId));
    }

    /**
     * Best-guess target field key for a Directory CSV header. Returns
     * null if nothing matches confidently — the import form treats that
     * as "skip this column" and the operator can still pick a target
     * manually.
     */
    public function guessDirectory(int $teamId, string $header): ?string
    {
        return $this->guess($this->directoryDefinitions($teamId), $header);
    }

    /**
     * @param  Collection<int, DirectoryFieldDefinition>  $definitions
     * @return array<string, string>
     */
    protected function buildOptions(Collection $definitions): array
    {
        $options = ['' => '— Skip this column —'];
        foreach ($definitions->sortBy('sort_order') as $def) {
            $options[$def->key] = $def->label;
        }
        return $options;
    }

    /**
     * @param  Collection<int, DirectoryFieldDefinition>  $definitions
     */
    protected function guess(Collection $definitions, string $header): ?string
    {
        $needle = $this->normalize($header);
        if ($needle === '') {
            return null;
        }

        // Pass 1: exact match against every definition's label and key.
        foreach ($definitions as $def) {
            if ($this->normalize($def->label) === $needle) {
                return $def->key;
            }
            if ($this->normalize($def->key) === $needle) {
                return $def->key;
            }
        }

        // Pass 2: alias-driven role match. If the header matches one
        // of the well-known aliases for a role, return the key of the
        // client's field assigned to that role (if any).
        foreach (self::ROLE_ALIASES as $role => $aliases) {
            if (! in_array($needle, $aliases, true)) {
                continue;
            }
            $byRole = $definitions->first(fn ($def) => $def->role === $role);
            if ($byRole) {
                return $byRole->key;
            }
        }

        return null;
    }

    protected function directoryDefinitions(int $teamId): Collection
    {
        return DirectoryFieldDefinition::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    protected function normalize(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($header)) ?? '';
    }
}
