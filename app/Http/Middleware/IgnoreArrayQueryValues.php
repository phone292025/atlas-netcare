<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drops array values from the query string.
 *
 * Every filter in this application takes one value. `?status[]=x` arrives as
 * an array, and ten list screens answered it with a 500, "Array to string
 * conversion", from whichever line first printed or compared it. Dropped, the
 * value reads as absent and the screen falls back to its default, the way an
 * unreadable `?week=` already does.
 *
 * Only the query string. Form bodies post arrays on purpose: checklist items,
 * inspection answers. Global rather than on the `web` group, so a screen added
 * later is covered without anybody remembering to.
 *
 * @see App\Support\CampusClock::dateFrom()
 */
class IgnoreArrayQueryValues
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->query->replace(array_filter($request->query->all(), fn ($value) => ! is_array($value)));

        return $next($request);
    }
}
