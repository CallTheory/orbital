<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

class OperatingHour extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'label',
        'day_of_week',
        'open_time',
        'close_time',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
