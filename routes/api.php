<?php

use App\Http\Controllers\Api\ReadingIngestController;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\LimitBodySize;
use Illuminate\Support\Facades\Route;

/*
| The machine API: what probe agents call (ADR-0017). Stateless, under
| /api, with no session, cookies or CSRF. A browser's JSON endpoints, which
| need a signed-in user, are in web.php.
|
| In this order: a limit per address before anything is checked, so a
| caller without a token can't keep the app busy; the size cap; the token;
| then a limit per agent.
*/

Route::post('v1/readings', ReadingIngestController::class)
    ->middleware(['throttle:agent-address', LimitBodySize::class, AuthenticateAgent::class, 'throttle:agent'])
    ->name('agent.readings');
