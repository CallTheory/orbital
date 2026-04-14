<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Extension;
use App\Models\User;
use App\Policies\Concerns\TenantResourcePolicy;

class ExtensionPolicy
{
    use TenantResourcePolicy {
        view as parentView;
    }

    protected function permissionPrefix(): string
    {
        return 'extension';
    }

    /**
     * Operators can only view extensions assigned to them. Tenant admins
     * and supervisors can view any extension in their tenant.
     */
    public function view(User $user, Extension $record): bool
    {
        if (! $this->parentView($user, $record)) {
            return false;
        }

        // If the user is *only* an operator, restrict to own assignment.
        if ($user->hasRole('operator') && ! $user->hasAnyRole(['tenant_admin', 'supervisor'])) {
            return $record->assignable_type === $user->getMorphClass()
                && (int) $record->assignable_id === (int) $user->id;
        }

        return true;
    }
}
