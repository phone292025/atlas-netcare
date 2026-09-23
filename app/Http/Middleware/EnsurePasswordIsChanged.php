<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a user who is still on an administrator-chosen password (Q6).
 *
 * `must_change_password` is set when an administrator creates an account or
 * resets a password, and on the seeded first administrator. Until the user
 * picks their own password, every screen but the profile redirects there.
 *
 * On the whole `web` group rather than aliased: a control applied route by
 * route only protects the routes somebody remembered to list.
 *
 * @see docs/02-open-questions.md Q6
 * @see app/Http/Controllers/Auth/PasswordController.php — clears the flag
 */
class EnsurePasswordIsChanged
{
    /**
     * The routes that stay open, or the redirect is a locked door.
     *
     * `profile.edit` holds the password form and is where we send them, so it
     * must be reachable or the redirect loops forever. `password.update` is the
     * form that clears the flag. `logout` matters because somebody who cannot
     * remember the password an administrator gave them needs a way out that is
     * not closing the tab.
     *
     * `profile.update` is on the same screen and would 302 mid-edit otherwise.
     */
    private const ALLOWED = [
        'profile.edit',
        'profile.update',
        'password.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        /*
         * The dashboard polls for telemetry every few seconds. Handing it a
         * 302 to an HTML page produces a console full of parse errors rather
         * than anything a reader can act on, and letting it through would mean
         * the data is readable while the screens are not.
         */
        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, 'Change your password before using Atlas NetCare.');
        }

        return redirect()
            ->route('profile.edit')
            ->with('warning', 'An administrator set this password, so somebody else knows it. Choose your own before you carry on.');
    }
}
