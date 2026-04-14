<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per Asterisk MOH class. Read at dial plan generation time.
 *
 * The `name` column becomes the literal class name in musiconhold.conf
 * — keep it lowercase, no spaces, and stable (changing it requires
 * updating any downstream references).
 */
class HoldMusicClass extends Model
{
    public const TYPE_BUILTIN = 'builtin';
    public const TYPE_STREAM = 'stream';
    public const TYPE_FILES = 'files';

    public const DEFAULT_CLASS_NAME = 'default';

    protected $fillable = [
        'name',
        'label',
        'description',
        'type',
        'stream_url',
        'stream_format',
        'files_directory',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Is this the protected default class? Default class is locked from
     * deletion / rename to keep dial plans stable.
     */
    public function isDefault(): bool
    {
        return $this->name === self::DEFAULT_CLASS_NAME;
    }
}
