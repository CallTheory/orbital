<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extended client-scope trait for models that can ALSO be owned
 * by a platform-level shared container attached to multiple
 * clients via a pivot. Currently used by `DirectoryEntry` and
 * `DirectoryFieldDefinition`.
 *
 * The default `BelongsToTeam` scope filters queries to
 * `team_id = currentTeam`. This trait extends that to:
 *
 *     team_id = currentTeam
 *  OR {sharedParentColumn} IN (SELECT {sharedParentColumn}
 *                              FROM {teamSharedPivotTable}
 *                              WHERE team_id = currentTeam
 *                                AND is_active = true)
 *
 * so any tenant-scoped query automatically sees the client's
 * private rows PLUS rows from every shared container they're
 * subscribed to. Admin management surfaces that explicitly
 * query by `->where('team_id', ...)` keep their current
 * client-only behavior because they never rely on the scope.
 *
 * Consumer requirements:
 *   - Declare `public const SHARED_PARENT_COLUMN = '...';`
 *   - Declare `public const TEAM_SHARED_PIVOT_TABLE = '...';`
 *
 * Escape hatch for "just this client's private rows" — any
 * explicit `->where('team_id', $id)` at the query site is
 * sufficient; the OR clause is harmless because no shared
 * row will have the matching team_id.
 *
 * Cross-client super-admin access: `withoutTeamScope()` as
 * per the base `BelongsToTeam` trait — unchanged shape.
 */
trait BelongsToTeamOrSharedPool
{
    public static function bootBelongsToTeamOrSharedPool(): void
    {
        static::addGlobalScope('team', function (Builder $builder) {
            $user = auth()->user();
            if (! $user) {
                return;
            }

            // Super-admins bypass the scope entirely — same as
            // the base BelongsToTeam trait. They drill into
            // specific clients via explicit query filters.
            if ($user->isSuperAdmin()) {
                return;
            }

            $currentTeam = $user->currentTeam;
            if (! $currentTeam) {
                return;
            }

            $model = $builder->getModel();
            $table = $model->getTable();
            $sharedCol = $model::SHARED_PARENT_COLUMN;
            $pivot = $model::TEAM_SHARED_PIVOT_TABLE;

            $builder->where(function (Builder $q) use ($table, $currentTeam, $sharedCol, $pivot) {
                $q->where("{$table}.team_id", $currentTeam->id)
                  ->orWhereIn("{$table}.{$sharedCol}", function ($sub) use ($currentTeam, $sharedCol, $pivot) {
                      $sub->select($sharedCol)
                          ->from($pivot)
                          ->where('team_id', $currentTeam->id)
                          ->where('is_active', true);
                  });
            });
        });

        // Auto-stamp team_id for client-created rows, same as
        // BelongsToTeam. Skipped entirely when shared_*_id is
        // already set (super-admins creating shared records).
        static::creating(function (Model $model) {
            $sharedCol = $model::SHARED_PARENT_COLUMN;

            // Caller is creating a shared row — don't touch team_id.
            if (! empty($model->{$sharedCol})) {
                return;
            }

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
     * Super-admin-only cross-client query. Same shape as
     * `BelongsToTeam::withoutTeamScope()` — use for admin
     * surfaces that need the raw view.
     */
    public static function withoutTeamScope(): Builder
    {
        $user = auth()->user();
        abort_unless($user?->isSuperAdmin(), 403, 'Cross-client queries are super-admin only.');

        return static::query()->withoutGlobalScope('team');
    }
}
