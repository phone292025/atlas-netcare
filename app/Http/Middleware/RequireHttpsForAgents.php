<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an agent request over plain HTTP from anywhere but this machine.
 *
 * An agent sends its token in every request. Over plain HTTP anybody on the
 * same network can read it and send readings as that agent, so a remote
 * agent must use HTTPS (docs/11-installation-guide.md, "HTTPS for remote
 * agents"). Plain HTTP is still accepted from this machine: a loopback
 * client, or any client of a Docker stack published on loopback only,
 * because inside the container every request arrives from Docker's gateway.
 * Either way the request must be addressed to localhost and carry no
 * forwarding header: a proxy, tunnel or port forward on this machine
 * connects from loopback too, may be passing on a request from anywhere,
 * and either adds a header or addresses the request to another name. The
 * same rule as App\Support\ResetLinkOnScreen.
 *
 * Runs before the token is checked, so a refused request says why without
 * saying whether its token was any good. `atlas.ingest.allow_plain_http`
 * turns the check off, for a closed lab network whose owner accepts that.
 */
class RequireHttpsForAgents
{
    private const LOOPBACK = ['127.0.0.0/8', '::1'];

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->secure() || config('atlas.ingest.allow_plain_http') || $this->fromThisMachine($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Agents must connect over HTTPS. This request came over plain HTTP from another machine, so its token could be read on the way.',
        ], 403);
    }

    private function fromThisMachine(Request $request): bool
    {
        if ($request->headers->has('X-Forwarded-For') || $request->headers->has('Forwarded') || $request->headers->has('Via')) {
            return false;
        }

        if (! in_array(strtolower($request->getHost()), self::LOCAL_HOSTS, true)) {
            return false;
        }

        $ip = (string) $request->server('REMOTE_ADDR');
        $ip = str_starts_with($ip, '::ffff:') ? substr($ip, 7) : $ip;

        return ($ip !== '' && IpUtils::checkIp($ip, self::LOOPBACK)) || config('atlas.published_on_loopback');
    }
}
