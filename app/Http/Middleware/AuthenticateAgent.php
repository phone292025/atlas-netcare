<?php

namespace App\Http\Middleware;

use App\Services\Telemetry\AgentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a probe agent in by its bearer token, and nothing else (ADR-0017).
 *
 * The token must be in the Authorization header. One in a query string ends
 * up in server logs and browser history, so it is ignored. The agent is put
 * on the request for the controller; it never becomes a signed-in user, so
 * the token opens no web screen.
 */
class AuthenticateAgent
{
    public function __construct(private readonly AgentService $agents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $agent = $this->agents->authenticate($request->bearerToken());

        if ($agent === null) {
            return response()->json(['message' => 'A valid agent token is required.'], 401, [
                'WWW-Authenticate' => 'Bearer realm="atlas"',
            ]);
        }

        $request->attributes->set('agent', $agent);

        return $next($request);
    }
}
