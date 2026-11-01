#!/usr/bin/env bash
# End to end, on a running stack: an agent's batch over HTTPS through the
# image's Caddy is stored, and the same batch over plain HTTP never reaches
# PHP with its token. Run from the project folder after `docker compose up`,
# with the same Compose settings in the environment. CI's Docker stack job
# runs it; it restarts the app service with HTTPS on, under the name
# atlas.localhost, so a .localhost certificate from Caddy's own authority.
set -euo pipefail

HTTPS_PORT="${ATLAS_HTTPS_PORT:-8443}"
HTTP_PORT="${ATLAS_PORT:-8000}"

# An agent with one device of its own, and its token, made in the container.
token=$(docker compose exec -T app php artisan tinker --execute='
    $made = app(App\Services\Telemetry\AgentService::class)->register("HTTPS check agent");
    $device = new App\Models\NetworkDevice(["kind" => "router", "code" => "RT-HTTPS", "name" => "HTTPS check", "location_id" => App\Models\Location::query()->value("id"), "ip_address" => "10.98.0.1"]);
    $device->agent_id = $made["agent"]->id;
    $device->save();
    echo $made["token"];')

batch() {
    printf '{"sent_at":"%s","agent_version":"https-check","readings":[{"sequence":%s,"device":"RT-HTTPS","measured_at":"%s","reachable":true,"latency_ms":3}]}' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

post() { # url, sequence, extra curl options...
    local url=$1 sequence=$2; shift 2
    curl -s -o /tmp/agent-answer.json -w '%{http_code}' "$@" \
        -H "Authorization: Bearer ${token}" -H 'Content-Type: application/json' -H 'Accept: application/json' \
        --data "$(batch "$sequence")" "$url"
}

echo "Plain HTTP to the default stack, through Docker's gateway:"
code=$(post "http://127.0.0.1:${HTTP_PORT}/api/v1/readings" 1)
echo "  ${code} $(head -c 160 /tmp/agent-answer.json)"
[ "$code" = "403" ]

ATLAS_SERVER_NAME=atlas.localhost ATLAS_URL="https://atlas.localhost:${HTTPS_PORT}" \
    docker compose up -d --wait --wait-timeout 120 app > /dev/null

echo "HTTPS through Caddy:"
code=$(post "https://atlas.localhost:${HTTPS_PORT}/api/v1/readings" 2 -k --resolve "atlas.localhost:${HTTPS_PORT}:127.0.0.1")
echo "  ${code} $(cat /tmp/agent-answer.json)"
[ "$code" = "200" ] && grep -q '"stored":\[2\]' /tmp/agent-answer.json

echo "Plain HTTP once HTTPS is on:"
code=$(post "http://127.0.0.1:${HTTP_PORT}/api/v1/readings" 3 -H 'Host: atlas.localhost')
echo "  ${code} (Caddy redirects port 80 to HTTPS before PHP sees the request)"
[ "$code" = "308" ]

stored=$(docker compose exec -T db psql -U atlas -d atlas -tAc "SELECT count(*) FROM telemetry_readings r JOIN network_devices d ON d.id = r.network_device_id WHERE d.code = 'RT-HTTPS'")
echo "Readings stored for RT-HTTPS: ${stored}"
[ "$stored" = "1" ]

# Back to plain HTTP on port 80, as the rest of CI expects.
docker compose up -d --wait --wait-timeout 120 app > /dev/null
echo "Agent HTTPS: OK"
