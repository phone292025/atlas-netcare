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
 * agents"). Plain HTTP is accepted only from a loopback client, addressed to
 * localhost, with no forwarding header: Atlas run without Docker, and an
 * agent on the same machine.
 *
 * Not from a Docker stack's gateway, even one published on loopback only.
 * Inside the container every request arrives from the gateway, so a reverse
 * proxy on the host that adds no forwarding header, as nginx's defaults
 * don't, would pass for this machine and carry tokens from anywhere. An
 * agent beside a Docker stack uses HTTPS too, or the opt-in below while
 * testing.
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
            'message' => 'Agents must connect over HTTPS. This request came over plain HTTP, so its token could be read on the way.',
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

        return $ip !== '' && IpUtils::checkIp($ip, self::LOOPBACK);
    }
}
