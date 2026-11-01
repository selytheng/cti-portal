<?php

namespace App\Policies;

use App\Models\User;

/**
 * RBAC per spec Section 2:
 *  - superadmin manages all users (also bypasses via Gate::before);
 *  - admin may only view/create/edit accounts in the `monitoring` role;
 *  - monitoring manages nobody.
 */
class UserPolicy
{
    public function before(User $actor, string $ability): ?bool
    {
        return $actor->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->can('users.manage-all') || $actor->can('users.manage-monitoring');
    }

    public function view(User $actor, User $target): bool
    {
        return $this->update($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.manage-all') || $actor->can('users.manage-monitoring');
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->can('users.manage-all')) {
            return true;
        }

        return $actor->can('users.manage-monitoring') && $target->hasRole('monitoring');
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can('users.manage-all') && $actor->id !== $target->id;
    }
}
