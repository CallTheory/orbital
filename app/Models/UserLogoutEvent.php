<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row written when an operator signs out of the operator
 * panel. Records who, when, and which LogoutReason they picked.
 *
 * `reason_label_snapshot` is the label text as it was at the
 * moment of logout — kept separately so that deleting or
 * renaming the LogoutReason row later doesn't rewrite history.
 */
class UserLogoutEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'logout_reason_id',
        'reason_label_snapshot',
        'logged_out_at',
    ];

    protected function casts(): array
    {
        return [
            'logged_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(LogoutReason::class, 'logout_reason_id');
    }
}
