<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A client's credential for the public v1 API.
 *
 * Deliberately not BelongsToTeam: the API authenticates by token before
 * any user or team context exists, and the admin pages query through
 * the team relationship.
 */
class TeamApiKey extends Model
{
    public const TOKEN_PREFIX = 'orb_';

    protected $fillable = [
        'team_id',
        'name',
        'token_hash',
        'token_prefix',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Create a key and return it with the plain token, which is not
     * recoverable afterwards.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Team $team, string $name): array
    {
        $token = self::TOKEN_PREFIX.Str::random(40);

        $key = self::create([
            'team_id' => $team->id,
            'name' => $name,
            'token_hash' => self::hashToken($token),
            'token_prefix' => substr($token, 0, 8),
        ]);

        return [$key, $token];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }
}
