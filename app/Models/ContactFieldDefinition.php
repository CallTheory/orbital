<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

/**
 * Schema entry for a Contact field. Each row defines one input on
 * the tenant's Contacts form: its slug, label, data type, whether
 * it's required, and (optionally) which semantic role it plays.
 *
 * Roles exist so downstream features — "Grant portal access",
 * record-title rendering, smart-ingest prompts, CSV import header
 * guessing — can locate the canonical name/email/phone/organization
 * field without hard-coding slugs. Example: a veterinary practice
 * might name its identity field "Client Name" while a law firm
 * uses "Attorney Name"; both have `role = name` so both work.
 */
class ContactFieldDefinition extends Model
{
    use BelongsToTeam;

    /** Supported field types — mapped to Filament components in FieldFormBuilder. */
    public const TYPES = [
        'text',
        'textarea',
        'number',
        'date',
        'datetime',
        'boolean',
        'select',
        'multi_select',
        'email',
        'url',
        'phone',
    ];

    /** Semantic role slots — at most one field per team per non-`none` role. */
    public const ROLES = ['name', 'email', 'phone', 'organization', 'none'];

    protected $fillable = [
        'team_id',
        'key',
        'label',
        'type',
        'options',
        'role',
        'required',
        'help_text',
        'placeholder',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
