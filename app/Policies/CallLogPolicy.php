<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallLog;
use App\Models\User;

/**
 * Call logs are system-generated — no create/update/delete permissions.
 * Operators can only see logs for extensions assigned to them.
 */
class CallLogPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->can('call_log.view_any');
    }

    public function view(User $user, CallLog $record): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ((int) $record->team_id !== (int) $user->current_team_id) {
            return false;
        }

        if (! $user->can('call_log.view')) {
            return false;
        }

        // Operators see only their own extension's logs.
        if ($user->hasRole('operator') && ! $user->hasAnyRole(['client_admin', 'supervisor'])) {
            if (! $record->extension) {
                return false;
            }
            return $record->extension->assignable_type === $user->getMorphClass()
                && (int) $record->extension->assignable_id === (int) $user->id;
        }

        return true;
    }

    public function export(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->can('call_log.export');
    }

    // No create / update / delete.
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CallLog $record): bool
    {
        return false;
    }

    public function delete(User $user, CallLog $record): bool
    {
        return false;
    }
}
