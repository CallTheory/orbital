<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Pending invitation to grant a User portal access to a client.
 *
 * The admin creates one of these from the Users tab when inviting
 * a new portal user. A mail goes out with a signed link; on accept
 * the invitation controller:
 *   - creates a User (if the email isn't already a User),
 *   - grants the `client_user` role scoped to this team,
 *   - attaches the User to the team via the team_user pivot,
 *   - stamps accepted_at and logs the user in.
 *
 * Only one pending invitation per (team, email) can exist at a time
 * — the unique index on the table rejects duplicates. To re-invite,
 * either cancel the old one or wait for it to expire.
 */
class ClientInvitation extends Model
{
    use HasFactory;

    public const ROLE_PORTAL_USER = 'portal_user';
    public const ROLE_ACCOUNT_MANAGER = 'account_manager';

    protected $fillable = [
        'team_id',
        'email',
        'role',
        'invited_by_user_id',
        'token',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Generate a fresh cryptographically-random token and a default
     * 14-day expiry. Callers use this to fill the required fields
     * right before insert, keeping the invitation factory boring.
     */
    public static function freshTokenAttributes(): array
    {
        return [
            'token' => Str::random(48),
            'expires_at' => now()->addDays(14),
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Pending = not accepted AND not expired. What the admin sees
     * in the "Pending invitations" tray above the Users list.
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }
}
