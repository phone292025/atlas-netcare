<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a deactivated account on its next request (FR-106).
 *
 * LoginRequest stops a deactivated account getting a new session, but an
 * existing session, or a "remember me" cookie, outlives the deactivation. The
 * role middleware only guards role-prefixed routes, so the shared ones
 * (tickets, notifications, the polling endpoints, the profile) stayed open.
 *
 * On the whole `web` group for the same reason as EnsurePasswordIsChanged: a
 * control applied route by route misses the next route somebody adds.
 * Logging out also cycles the remember token, so the cookie stops working.
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'This account has been deactivated. Contact the IT office.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(Response::HTTP_UNAUTHORIZED, self::MESSAGE);
        }

        return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
    }
}
