<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeamOrSharedPool;
use Illuminate\Database\Eloquent\Model;

/**
 * Schema entry for a DirectoryEntry field. Parallels
 * ContactFieldDefinition — same shape, separate table, different
 * vocabulary scoped by use case (directory fields drive call-time
 * routing, contact fields drive outbound communication).
 */
class DirectoryFieldDefinition extends Model
{
    use BelongsToTeamOrSharedPool;

    public const SHARED_PARENT_COLUMN = 'shared_directory_id';
    public const TEAM_SHARED_PIVOT_TABLE = 'team_shared_directory';

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

    public const ROLES = ['name', 'email', 'phone', 'organization', 'none'];

    protected $fillable = [
        'team_id',
        'shared_directory_id',
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
