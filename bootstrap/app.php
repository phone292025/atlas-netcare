<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\IgnoreArrayQueryValues;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Area-level authorisation. Usage: middleware('role:engineer,admin')
        // See docs/03-architecture.md
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // Account-level controls, on the whole group rather than aliased,
        // because a control you have to remember to apply is one somebody will
        // forget on the next screen they add. The deactivation check runs
        // first: a deactivated account is signed out, not sent to change its
        // password.
        $middleware->appendToGroup('web', [
            EnsureAccountIsActive::class,
            EnsurePasswordIsChanged::class,
        ]);

        // Every filter takes one value; `?status[]=x` crashed ten screens.
        $middleware->append(IgnoreArrayQueryValues::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
