<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),

                    // Somebody who reset through the emailed link chose this
                    // password themselves, so there is nothing left to force.
                    // Without this they would sign in and be sent straight
                    // back to the profile screen to change it again.
                    'must_change_password' => false,
                ])->save();

                $this->signOutEverywhere($user);

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }

    /**
     * End every session belonging to this account.
     *
     * Rotating remember_token above, which is all Breeze does, invalidates
     * "remember me" cookies and nothing else. An active session survives a
     * password reset untouched — so somebody who has taken over an account
     * stays signed in while the owner resets the password and believes they
     * have locked them out. Locking them out is the entire reason the feature
     * exists.
     *
     * Only possible because SESSION_DRIVER is `database` and Laravel's
     * sessions table carries user_id. On the `file` or `array` drivers there
     * is nothing to query, so this quietly does nothing rather than throwing
     * — a reset must still succeed on a machine configured differently.
     *
     * This signs out the person doing the reset too, if they were signed in
     * on this device. That is intended: the redirect goes to the login screen
     * either way, and signing in once with the new password is the proof it
     * worked.
     *
     * @see docs/02-open-questions.md Q11
     */
    private function signOutEverywhere(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }
}
