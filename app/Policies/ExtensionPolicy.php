<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Extension;
use App\Models\User;
use App\Policies\Concerns\ClientResourcePolicy;

class ExtensionPolicy
{
    use ClientResourcePolicy {
        view as parentView;
    }

    protected function permissionPrefix(): string
    {
        return 'extension';
    }

    /**
     * Operators can only view extensions assigned to them. Client admins
     * and supervisors can view any extension in their client.
     */
    public function view(User $user, Extension $record): bool
    {
        if (! $this->parentView($user, $record)) {
            return false;
        }

        // If the user is *only* an operator, restrict to own assignment.
        if ($user->hasRole('operator') && ! $user->hasAnyRole(['client_admin', 'supervisor'])) {
            return $record->assignable_type === $user->getMorphClass()
                && (int) $record->assignable_id === (int) $user->id;
        }

        return true;
    }
}
