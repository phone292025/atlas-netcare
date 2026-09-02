<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 12 ships this class empty. AuthorizesRequests is added here so every
 * controller can call $this->authorize(), which is how per-record permission
 * checks reach a Policy.
 *
 * Middleware answers "may this role reach this area?"; a Policy answers "may
 * this user act on this record?". Never write `if ($user->role === ...)` in a
 * controller — that condition belongs in one of the two.
 *
 * @see docs/03-architecture.md — Authorisation
 */
abstract class Controller
{
    use AuthorizesRequests;
}
