<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a user who is still on an administrator-chosen password.
 *
 * `users.must_change_password` was created in Sprint 0 because `04-database.md`
 * specified it, and three places set it: an administrator creating an account,
 * an administrator resetting somebody's password, and the seeded first
 * administrator. Nothing read it. The account screen showed a badge saying
 * "Must be changed at next sign-in" and the installation guide told a reader
 * the administrator "asks for a new one immediately", and neither was true:
 * you signed in and went straight to your dashboard.
 *
 * That was not an oversight. Q6 asked the team whether a first login should
 * force a change, nobody answered, and the project's rule is that no behaviour
 * depending on an undecided question gets built. So the column was written and
 * the middleware was not. Q6 was decided on 2026-09-04 and this is it.
 *
 * WHY THE `web` GROUP RATHER THAN AN ALIAS. A control applied route by route
 * only protects the routes somebody remembered to list, and the next person to
 * add a screen will not remember. This runs on every web request and lets the
 * unauthenticated ones straight through.
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
