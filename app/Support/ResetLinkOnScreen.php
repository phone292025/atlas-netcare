<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether the forgot-password page may show the reset link itself.
 *
 * Only on a development machine that cannot send mail, and only to somebody
 * at that machine. With the .env.example defaults the first three are true,
 * so without the last one anybody who could reach the app could ask for
 * admin@atlas.local's link and read it off their own screen.
 *
 * At that machine means a loopback client, or a Docker stack published on
 * loopback only (compose passes ATLAS_BIND in), because inside the container
 * every request arrives from Docker's gateway, never from 127.0.0.1. Either
 * way the request must also be addressed to localhost and carry no
 * forwarding header: a tunnel or proxy on the host (ngrok, cloudflared, port
 * forwarding) connects from loopback too, and adds one or the other. A forged
 * header can only take the link away, never grant it.
 *
 * The request's parts are passed in: this layer never reads the request.
 */
final class ResetLinkOnScreen
{
    private const LOOPBACK = ['127.0.0.0/8', '::1'];

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    public static function allowed(?string $clientIp, ?string $host = null, bool $forwarded = false): bool
    {
        if (! app()->environment('local') || ! config('app.debug') || config('atlas.mail_configured')) {
            return false;
        }

        if ($forwarded || ! in_array(strtolower((string) $host), self::LOCAL_HOSTS, true)) {
            return false;
        }

        $ip = $clientIp !== null && str_starts_with($clientIp, '::ffff:') ? substr($clientIp, 7) : $clientIp;

        return ($ip !== null && IpUtils::checkIp($ip, self::LOOPBACK))
            || config('atlas.published_on_loopback');
    }
}
