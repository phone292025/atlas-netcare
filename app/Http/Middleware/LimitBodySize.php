<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request body larger than `atlas.ingest.max_kilobytes` with 413,
 * before it is parsed. The batch rules cap readings at 500, but a body can
 * be large without being many readings.
 */
class LimitBodySize
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('atlas.ingest.max_kilobytes') * 1024;

        if ((int) $request->header('Content-Length', '0') > $limit || strlen($request->getContent()) > $limit) {
            return response()->json([
                'message' => 'The batch is larger than '.config('atlas.ingest.max_kilobytes').' KB. Send fewer readings at a time.',
            ], 413);
        }

        return $next($request);
    }
}
