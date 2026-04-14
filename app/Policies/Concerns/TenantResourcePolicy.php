<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared behavior for policies on BelongsToTeam domain models.
 *
 * Subclasses just declare the permission prefix (e.g. 'sip_trunk')
 * and this trait handles the standard CRUD matrix:
 *   - super_admin can do anything
 *   - same-tenant check on view/update/delete (defensive; global scope
 *     already filters cross-tenant, but double-check)
 *   - otherwise delegate to Spatie's permission check
 */
trait TenantResourcePolicy
{
    abstract protected function permissionPrefix(): string;

    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->can($this->permissionPrefix().'.view_any');
    }

    public function view(User $user, Model $record): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (! $this->sameTenant($user, $record)) {
            return false;
        }
        return $user->can($this->permissionPrefix().'.view');
    }

    public function create(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->can($this->permissionPrefix().'.create');
    }

    public function update(User $user, Model $record): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (! $this->sameTenant($user, $record)) {
            return false;
        }
        return $user->can($this->permissionPrefix().'.update');
    }

    public function delete(User $user, Model $record): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (! $this->sameTenant($user, $record)) {
            return false;
        }
        return $user->can($this->permissionPrefix().'.delete');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->delete($user, $record);
    }

    protected function sameTenant(User $user, Model $record): bool
    {
        return (int) $record->team_id === (int) $user->current_team_id;
    }
}
