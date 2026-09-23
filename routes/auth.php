<?php

/*
| No registration: administrators create every account (FR-105, FR-401, Q6).
|
| Password reset is FR-102. Delivery needs a Gmail app password in
| MAIL_PASSWORD; until one is set, the forgot-password screen says so rather
| than pretending (Q11). Email verification and password confirmation are
| not requirements and stay removed; RemovedAuthFeaturesTest keeps them out.
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

    // Throttled per address by the password broker, and per client here.
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

});

/*
| THE RESET LINK ITSELF IS DELIBERATELY NOT BEHIND `guest`.
|
| Breeze puts it there, and that is wrong for a URL that arrives by email:
| nobody controls which browser opens a link from their inbox, and being
| signed in somewhere is exactly the state a person who has forgotten their
| password is often in. Behind `guest` the link silently redirects into the
| application instead of showing the form, which reads as a broken link.
| That is what happened on a phone on 2026-08-29.
|
| It is not a hole. The signed token in the URL is the authorisation, and it
| is checked either way; whether a session happens to exist alongside it says
| nothing about whether the holder opened that mailbox. What the session does
| change is the wording on the form, which names who is signed in and whose
| password is about to change.
|
| The request form above stays behind `guest`. Somebody already signed in has
| no reason to ask for a link — the profile screen changes their password and
| asks for the current one.
*/
Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
    ->name('password.reset');

Route::post('reset-password', [NewPasswordController::class, 'store'])
    ->name('password.store');

Route::middleware('auth')->group(function () {
    // Changing your own password from the profile screen. Needs the current
    // password and sends nothing anywhere.
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
