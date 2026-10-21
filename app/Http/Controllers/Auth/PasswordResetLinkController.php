<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * FR-102, step one: ask for a link.
 *
 * @see docs/02-open-questions.md Q11 for why delivery was the hard part
 */
class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Look the account up by its stored form. See User::normalizeEmail().
        $request->merge(['email' => User::normalizeEmail($request->string('email')->toString())]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        /*
         * Password::sendResetLink() sends the notification inline, so a
         * refused SMTP login throws right here and Laravel's handler turns
         * that into a 500 with a stack trace on the password screen. The most
         * likely cause by far is an app password that was never pasted, was
         * pasted with Google's display spaces still in it, or has been
         * revoked — none of which is worth a stack trace.
         *
         * The token row has already been written at this point. That is
         * harmless: it goes unused and expires on config('auth.passwords').
         */
        try {
            Password::sendResetLink($request->only('email'));
        } catch (TransportExceptionInterface $e) {
            // Only reachable for a real account, so this does say one exists.
            // A broken mail server is an administrator's problem to hear
            // about, and pretending a link is on its way would be worse.
            Log::error('Password reset mail failed to send.', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('We could not send the email. Tell an administrator that the mail server rejected it.')]);
        }

        /*
         * One answer whatever the broker said. "We can't find a user with
         * that email address" and "a link was sent moments ago" both tell a
         * stranger which addresses have accounts, which the login form is
         * careful never to do. The broker's own throttle (one link per address
         * per config('auth.passwords.*.throttle') seconds) still applies; the
         * sentence says so, because a second click otherwise looks broken.
         */
        $seconds = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.throttle');

        return back()->with('status', __('If that address has an account, a reset link is on its way. Only one link is sent every :seconds seconds.', [
            'seconds' => $seconds,
        ]));
    }
}
