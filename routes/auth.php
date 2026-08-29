<?php

/*
| Registration is intentionally absent. Atlas NetCare is an internal,
| privilege-based system: administrators create every account (FR-105, FR-401).
| See docs/04-open-questions.md Q6 and the project Keynote in the proposal.
|
| PASSWORD RESET IS FR-102 AND IS APPROVED IN THE PROPOSAL, which lists
| "recover password" for all three roles. It was briefly removed on 2026-08-29
| because it was lying to the user, and restored the same day once the
| proposal was checked. Being broken is grounds for fixing a requirement, not
| for deleting it.
|
| The thing that was broken is DELIVERY, not the flow. Every demo account uses
| @apu.test or @atlas.local, reserved domains that can never receive mail, and
| MAIL_MAILER is `log` — so the screen said "We have emailed your password
| reset link" while writing the link into storage/logs/laravel.log. Seven
| accumulated there before anybody noticed.
|
| See docs/04-open-questions.md Q11 for the delivery options and what the team
| chose.
|
| EMAIL VERIFICATION AND PASSWORD CONFIRMATION stay removed. Neither appears in
| the proposal or in docs/03-requirements.md, and neither could ever run: User
| does not implement MustVerifyEmail, and no route carried either middleware.
| RemovedAuthFeaturesTest keeps them out.
|
| @see docs/progress/decision-log.md — 2026-08-29
*/

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // FR-102.
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    // Changing your own password from the profile screen. Needs the current
    // password and sends nothing anywhere.
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
