#!/usr/bin/env bash
# End to end, on a running stack: an agent's batch over HTTPS through the
# image's Caddy is stored, the same batch over plain HTTP never reaches PHP
# with its token, and the real agent binary trusts Atlas only through its
# ca_file. Run from the project folder after `docker compose up`, with the
# same Compose settings in the environment, and ATLAS_AGENT_BIN pointing at
# a built atlas-agent. CI's Docker stack job runs it.
#
# It restarts the app service with HTTPS on for the address 127.0.0.1, so a
# certificate from Caddy's own authority with no name to resolve, and puts
# it back to plain HTTP at the end.
set -euo pipefail

HTTPS_PORT="${ATLAS_HTTPS_PORT:-8443}"
HTTP_PORT="${ATLAS_PORT:-8000}"
AGENT_BIN="${ATLAS_AGENT_BIN:?Set ATLAS_AGENT_BIN to a built atlas-agent}"
WORK=$(mktemp -d)
native() { if command -v cygpath > /dev/null; then cygpath -m "$1"; else echo "$1"; fi; }

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
    curl -s -o "$WORK/answer.json" -w '%{http_code}' "$@" \
        -H "Authorization: Bearer ${token}" -H 'Content-Type: application/json' -H 'Accept: application/json' \
        --data "$(batch "$sequence")" "$url"
}

stored() {
    docker compose exec -T db psql -U atlas -d atlas -tAc "SELECT count(*) FROM telemetry_readings r JOIN network_devices d ON d.id = r.network_device_id WHERE d.code = 'RT-HTTPS'"
}

echo "Plain HTTP to the default stack, through Docker's gateway:"
code=$(post "http://127.0.0.1:${HTTP_PORT}/api/v1/readings" 1)
echo "  ${code} $(head -c 160 "$WORK/answer.json")"
[ "$code" = "403" ]

ATLAS_SERVER_NAME=127.0.0.1 ATLAS_URL="https://127.0.0.1:${HTTPS_PORT}" \
    docker compose up -d --wait --wait-timeout 120 app > /dev/null

echo "HTTPS through Caddy:"
code=$(post "https://127.0.0.1:${HTTPS_PORT}/api/v1/readings" 2 -k)
echo "  ${code} $(cat "$WORK/answer.json")"
[ "$code" = "200" ]
grep -q '"stored":\[2\]' "$WORK/answer.json"

echo "Plain HTTP once HTTPS is on:"
code=$(post "http://127.0.0.1:${HTTP_PORT}/api/v1/readings" 3)
echo "  ${code} (Caddy redirects port 80 to HTTPS before PHP sees the request)"
[ "$code" = "308" ]
[ "$(stored)" = "1" ]

# The real agent, which verifies the certificate: first without Atlas's
# root, when nothing may get through, then with it as ca_file.
docker compose cp app:/data/caddy/pki/authorities/local/root.crt "$WORK/atlas-root.crt" > /dev/null 2>&1
printf '%s' "$token" > "$WORK/token.txt"
agent_config() { # ca_file line
    cat > "$WORK/atlas-agent.yaml" <<YAML
atlas_url: https://127.0.0.1:${HTTPS_PORT}
token_file: $(native "$WORK/token.txt")
$1
interval: 2s
timeout: 1s
buffer: $(native "$WORK")/agent-$RANDOM.db
devices:
  - code: RT-HTTPS
    ping: false
    http: https://127.0.0.1:${HTTPS_PORT}/up
YAML
}

echo "The agent without Atlas's root certificate:"
agent_config ""
timeout 8 "$AGENT_BIN" -config "$(native "$WORK/atlas-agent.yaml")" 2> "$WORK/untrusted.log" || true
grep -m1 -o 'certificate[^"]*' "$WORK/untrusted.log" | sed 's/^/  /'
grep -q 'certificate' "$WORK/untrusted.log"
[ "$(stored)" = "1" ]

echo "The agent with it as ca_file:"
agent_config "ca_file: $(native "$WORK/atlas-root.crt")"
timeout 8 "$AGENT_BIN" -config "$(native "$WORK/atlas-agent.yaml")" 2> "$WORK/trusted.log" || true
now=$(stored)
echo "  readings stored for RT-HTTPS: ${now}"
[ "$now" -gt 1 ]

# Back to plain HTTP on port 80, as the rest of CI expects.
docker compose up -d --wait --wait-timeout 120 app > /dev/null
rm -rf "$WORK"
echo "Agent HTTPS: OK"
