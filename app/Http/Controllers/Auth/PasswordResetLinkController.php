<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
 * @see docs/04-open-questions.md Q11 for why delivery was the hard part
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
            $status = Password::sendResetLink($request->only('email'));
        } catch (TransportExceptionInterface $e) {
            Log::error('Password reset mail failed to send.', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('We could not send the email. Tell an administrator that the mail server rejected it.')]);
        }

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withInput($request->only('email'))
                ->withErrors(['email' => $this->explain($status)]);
    }

    /**
     * Laravel's stock wording, except where it leaves out the useful part.
     *
     * The broker allows one request per address per
     * config('auth.passwords.*.throttle') seconds, which is a real control —
     * without it anyone can fill a colleague's inbox with reset links. But it
     * answers with "Please wait before retrying", which does not say how long,
     * so it reads like the button is broken. Somebody clicking twice during a
     * demonstration is exactly who sees it.
     *
     * The number is read from config rather than written into the sentence,
     * for the same reason the expiry in the email is.
     */
    private function explain(string $status): string
    {
        if ($status !== Password::RESET_THROTTLED) {
            return __($status);
        }

        $seconds = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.throttle');

        return __('A link was sent to that address moments ago. Wait :seconds seconds before asking for another.', [
            'seconds' => $seconds,
        ]);
    }
}
