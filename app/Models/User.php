<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasRoles;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The built-in "taking work" sentinel slug. Every other
     * allowed value comes from the AvailabilityReason table,
     * which super-admins manage under Platform → Availability
     * Reasons. Only `available` is hard-coded because it's the
     * implicit default state for operators who don't need a
     * specific reason to be working.
     */
    public const AVAILABILITY_AVAILABLE = 'available';

    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
        'locale',
        'availability_status',
        'availability_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected $appends = [
        'profile_photo_url',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'availability_changed_at' => 'datetime',
        ];
    }

    /**
     * True when this user should receive new work right now.
     * Used by the Asterisk queue-member sync to pause routing
     * and by the email inbox to hide new unclaimed threads.
     *
     * The built-in `available` state always qualifies. Any other
     * status is a row in `availability_reasons`, and only blocks
     * new work if that row's `blocks_new_work` flag is on — soft
     * statuses (e.g. a "Back in 5" label) can leave it off so the
     * operator still receives routing despite the visible label.
     */
    public function isAvailableForWork(): bool
    {
        if ($this->availability_status === self::AVAILABILITY_AVAILABLE) {
            return true;
        }

        $reason = \App\Models\AvailabilityReason::query()
            ->where('slug', $this->availability_status)
            ->first();

        return $reason !== null && ! $reason->blocks_new_work;
    }

    /**
     * Preferred timezone for rendering dates to this user. Falls
     * back to the platform default when the user hasn't picked
     * one on their profile page. Pass the return value to
     * Filament table/column `->timezone()` modifiers or Carbon's
     * `->setTimezone()` at display time — database writes keep
     * using UTC via `config('app.timezone')`.
     */
    public function displayTimezone(): string
    {
        return ! empty($this->timezone)
            ? $this->timezone
            : (string) config('app.timezone');
    }

    /**
     * Preferred UI language for this user, falling back to the
     * platform default when unset. The `ApplyUserPreferences`
     * middleware calls `app()->setLocale()` with this value on
     * every authenticated request.
     */
    public function displayLocale(): string
    {
        return ! empty($this->locale)
            ? $this->locale
            : (string) config('app.locale');
    }

    /**
     * Check if this user holds the team-less super_admin role.
     *
     * Temporarily clears the team context so Spatie evaluates the role
     * at the platform (null team) level regardless of currentTeam.
     */
    public function isSuperAdmin(): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId(null);
        try {
            return $this->hasRole('super_admin');
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
        }
    }

    /**
     * Does this user hold any team-less platform role?
     *
     * Used to gate access to the operator workspace. Role names are
     * platform-operator-defined, so we check by team_id rather than
     * by hardcoded role names.
     */
    public function hasAnyPlatformRole(): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId(null);
        try {
            $this->unsetRelation('roles');
            return $this->roles->isNotEmpty();
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
        }
    }

    /**
     * Does this user belong to at least one real tenant team?
     *
     * "Real tenant" = a Team row with `personal_team = false`. The
     * super-admin's "Platform" team is `personal_team = true` and
     * doesn't count, so a super-admin only passes this check when
     * they've been explicitly attached to a customer tenant — i.e.
     * when the platform operator is dog-fooding their own product.
     */
    public function belongsToAnyTenant(): bool
    {
        return $this->teams()
            ->where('personal_team', false)
            ->exists();
    }

    /**
     * Gate access to every Filament panel on the platform.
     *
     *   admin    — super_admin only
     *   operator — any team-less platform role (super_admin, operator,
     *              supervisor, or whatever else is defined)
     *   portal   — users who belong to at least one real tenant team.
     *              Super-admin is NOT auto-admitted — they only see the
     *              portal if they've been explicitly added to a tenant
     *              (dog-fooding scenario).
     *
     * When a user hits the wrong panel, Filament's Authenticate middleware
     * falls back to `aborts` / 403. PanelRedirect runs earlier in the
     * middleware chain and turns those denials into a friendly redirect
     * to the user's actual home panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->isSuperAdmin(),
            'operator' => $this->hasAnyPlatformRole(),
            'portal' => $this->belongsToAnyTenant(),
            default => false,
        };
    }

    public function extensions(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Extension::class, 'assignable');
    }

    /**
     * Agent group memberships — polymorphic. A staff user can belong to
     * one or more platform agent groups (queues ring those groups).
     */
    public function agentGroupMemberships(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(AgentGroupMember::class, 'member');
    }

    /**
     * Operator skills — used by the QueueMemberSyncer to compute
     * which queues this operator can answer for in the shared
     * pool. Pivot carries `level` (1–5) and an optional `notes`
     * field for operator-side context like certification dates.
     */
    public function skills(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'skill_user')
            ->withPivot(['level', 'notes'])
            ->withTimestamps();
    }
}
