<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw key/value row in the platform_settings table.
 *
 * Direct usage is discouraged — go through PlatformSettingsRepository so
 * cache invalidation and audit fields stay consistent.
 */
class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
