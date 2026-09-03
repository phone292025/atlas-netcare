<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserHasRole;
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

        // Nobody uses the system on a password an administrator chose for them
        // (Q6, decided 2026-09-04). Appended to the group rather than aliased,
        // because a control you have to remember to apply is one somebody will
        // forget on the next screen they add.
        $middleware->appendToGroup('web', EnsurePasswordIsChanged::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
