<?php

/*
| Registration is intentionally absent. Atlas NetCare is an internal,
| privilege-based system: administrators create every account (FR-105, FR-401).
| See docs/04-open-questions.md Q6 and the project Keynote in the proposal.
|
| SO ARE PASSWORD RESET, EMAIL VERIFICATION AND PASSWORD CONFIRMATION, all of
| which Breeze installs by default and none of which this system can honestly
| offer.
|
| Password reset emailed a link. Every account here uses @apu.test or
| @atlas.local, reserved domains that can never receive mail, and MAIL_MAILER
| is `log` — so the screen said "We have emailed your password reset link"
| while writing the link into storage/logs/laravel.log. Seven of them
| accumulated there before anybody noticed. A feature that lies to the user is
| worse than one that is missing, and a reset token sitting in a log file is a
| way into somebody's account for anyone who can read it.
|
| The real recovery path already exists and matches how the system works:
| an administrator sets a new password from the user management screen, and it
| is flagged must_change_password. The login page says so.
|
| Email verification was never live. User does not implement MustVerifyEmail
| and no route carries the `verified` middleware, so those three routes could
| not do anything.
|
| Password confirmation was never applied to a route either.
|
| If email is ever configured against real @apu.edu.my addresses, reset is
| worth restoring. Breeze's controllers are gone, so bring them back with
| `php artisan make:` rather than hunting for the originals.
|
| @see docs/progress/decision-log.md — 2026-08-29
*/

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    // Changing your own password from the profile screen. This one is real:
    // it needs the current password and sends nothing anywhere.
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
