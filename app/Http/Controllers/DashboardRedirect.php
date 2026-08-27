<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Sends a signed-in user to the home screen for their role.
 *
 * Each role has a different starting point: an assistant wants today's shift,
 * an engineer wants the health dashboard, an admin wants user management. One
 * shared "/" that redirects means no role ever sees another role's landing
 * page, and no link in the system has to know who is clicking it.
 *
 * Module routes arrive over Sprints 1-4, so most targets do not exist yet. An
 * unregistered target falls back to the profile page rather than throwing —
 * this controller must never be the reason the application is unreachable.
 *
 * @see docs/05-architecture.md — Authorisation
 * @see App\Enums\UserRole::homeRoute()
 */
class DashboardRedirect extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $role = $request->user()?->role;

        if (! $role instanceof UserRole) {
            // No role, or a raw string the model is not casting yet. Somewhere
            // safe that every authenticated user can reach.
            return redirect()->route('profile.edit');
        }

        $target = $role->homeRoute();

        return Route::has($target)
            ? redirect()->route($target)
            : redirect()->route('profile.edit');
    }
}
