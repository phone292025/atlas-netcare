<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     *
     * Clearing `must_change_password` here is what ends the redirect loop that
     * EnsurePasswordIsChanged puts a flagged user in — this is the only screen
     * they can reach, so it has to be the way out.
     *
     * It is set directly rather than mass assigned because it is not in
     * `$fillable`, and it must never be: a user posting
     * `must_change_password=false` alongside their new password would
     * otherwise let themselves off.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();
        $user->password = Hash::make($validated['password']);
        $user->must_change_password = false;
        $user->save();

        return back()->with('status', 'password-updated');
    }
}
