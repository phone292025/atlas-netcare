<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may act on which account.
 *
 * Middleware already answered "may this role reach the admin area?" — that is
 * EnsureUserHasRole. This answers the finer question the middleware cannot:
 * may THIS administrator act on THIS account?
 *
 * @see docs/03-architecture.md — Authorisation
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * An administrator must not deactivate themselves.
     *
     * Not paternalism — with no self-registration, the last administrator
     * locking themselves out leaves no way back into the system.
     */
    public function deactivate(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->isNot($target);
    }

    /** Nor demote themselves out of the admin role, for the same reason. */
    public function changeRole(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->isNot($target);
    }
}
