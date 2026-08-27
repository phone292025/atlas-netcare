<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Area-level authorisation: may this role reach this part of the system at all?
 *
 * Registered as the `role` alias. Accepts one or more roles:
 *
 *     Route::middleware('role:assistant')->group(...);
 *     Route::middleware('role:engineer,admin')->group(...);
 *
 * This answers "may this role reach this area?" and nothing finer. Per-record
 * questions ("may this engineer close THIS ticket?") belong in a Policy, never
 * here and never as an inline role check inside a controller.
 *
 * @see docs/05-architecture.md — Authorisation
 * @see docs/03-requirements.md FR-104, FR-106
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Unauthenticated requests are the `auth` middleware's job, not ours.
        // Reaching here without a user means the route is misconfigured, so
        // fail closed rather than letting the request through.
        if ($user === null) {
            abort(403, 'Authentication required.');
        }

        // A deactivated account keeps its history but loses access (FR-106).
        //
        // Deliberately a loose truthiness check, not `=== false`. Without a
        // model cast Eloquent hands back int(0) for a boolean column, so a
        // strict comparison never matches and the guard fails OPEN. A missing
        // attribute means active, so this is safe before the column exists.
        if (! ($user->is_active ?? true)) {
            abort(403, 'This account has been deactivated.');
        }

        if (! $this->hasAnyRole($user->role, $roles)) {
            abort(403, 'You do not have access to this area.');
        }

        return $next($request);
    }

    /**
     * @param  UserRole|string|null  $userRole  Cast to UserRole on the model,
     *                                          but a raw string is tolerated so
     *                                          this class is not coupled to the
     *                                          cast being configured.
     * @param  array<int, string>  $allowed  Role values from the route definition.
     */
    private function hasAnyRole(UserRole|string|null $userRole, array $allowed): bool
    {
        if ($userRole === null || $allowed === []) {
            return false;
        }

        // Route definitions carry roles as plain strings, so compare on the
        // backing value rather than on the enum instance.
        $value = $userRole instanceof UserRole ? $userRole->value : $userRole;

        return in_array($value, $allowed, strict: true);
    }
}
