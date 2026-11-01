<?php

/*
 * The app container's health check (compose.yaml): exit 0 when Laravel's /up
 * route answers.
 *
 * Plain HTTP on port 80 by default. When ATLAS_SERVER_NAME names a host,
 * Caddy serves HTTPS for it and redirects port 80 there, so the check asks
 * on 443 under that name instead. It skips the certificate check: the
 * container is asking itself, and its own CA may not be trusted inside it.
 */

$name = trim(explode(',', (string) (getenv('SERVER_NAME') ?: ':80'))[0]);

if ($name === '' || str_starts_with($name, ':') || str_starts_with($name, 'http://')) {
    exit(@file_get_contents('http://127.0.0.1/up') === false ? 1 : 0);
}

$host = parse_url(str_contains($name, '://') ? $name : 'https://'.$name, PHP_URL_HOST);

$context = stream_context_create([
    'http' => ['header' => "Host: {$host}"],
    'ssl' => ['peer_name' => $host, 'verify_peer' => false, 'verify_peer_name' => false],
]);

exit(@file_get_contents('https://127.0.0.1/up', false, $context) === false ? 1 : 0);
