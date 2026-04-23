<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope('team', function (Builder $builder) {
            $user = auth()->user();
            if (! $user) {
                return;
            }

            // Super-admins always bypass the team scope. They use ClientResource
            // sub-pages to drill into specific clients, which scope queries via
            // the relationship — not via auth context. Filtering super-admins
            // by their own personal team would hide client data they need to see.
            if ($user->isSuperAdmin()) {
                return;
            }

            if ($user->currentTeam) {
                $builder->where(
                    $builder->getModel()->getTable().'.team_id',
                    $user->currentTeam->id
                );
            }
        });

        static::creating(function (Model $model) {
            // Auto-set team_id from the auth'd user's current team ONLY for
            // non-super-admin users. Super-admins explicitly pick the client
            // via the relationship parent or the form picker.
            if (! $model->team_id && auth()->check()) {
                $user = auth()->user();
                if (! $user->isSuperAdmin() && $user->currentTeam) {
                    $model->team_id = $user->currentTeam->id;
                }
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Explicit cross-client query, super-admin only. Use this inside
     * ClientResource analytics and the Tenancy service namespace —
     * anywhere else it's a smell.
     */
    public static function withoutTeamScope(): Builder
    {
        $user = auth()->user();
        abort_unless($user?->isSuperAdmin(), 403, 'Cross-client queries are super-admin only.');

        return static::query()->withoutGlobalScope('team');
    }
}
