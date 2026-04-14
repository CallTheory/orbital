<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\QuotaExceededException;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

/**
 * Enforces the suspended-tenant block at model creation time.
 *
 * Super-admins always bypass. Tenant quotas for SIP trunks and
 * extensions are gone — tenants are read-only customer accounts and
 * never create those resources themselves. Only the suspension guard
 * remains; billing-relevant ceilings live elsewhere (max_concurrent
 * calls is enforced at call-start time, not at model creation).
 */
class QuotaObserver
{
    public function creating(Model $model): void
    {
        $user = auth()->user();

        // No auth context (seeders, queue jobs, tests) — skip.
        if (! $user) {
            return;
        }

        // Super-admins bypass.
        if ($user->isSuperAdmin()) {
            return;
        }

        $team = Team::find($model->team_id ?? $user->current_team_id);
        if (! $team) {
            return;
        }

        // Suspended tenants can't create anything.
        if ($team->suspended_at !== null) {
            throw new QuotaExceededException(
                resource: 'operations',
                limit: 0,
                message: 'This tenant is suspended. '.config('orbital.support_message').' to reactivate.',
            );
        }
    }
}
