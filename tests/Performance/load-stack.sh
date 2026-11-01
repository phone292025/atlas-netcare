#!/usr/bin/env bash
# Build a throwaway stack from the working tree, fill it to campus size, and
# measure response times against it (NFR-02, docs/07-testing.md).
#
#   bash tests/Performance/load-stack.sh          build, seed, measure, remove
#   KEEP=1 bash tests/Performance/load-stack.sh   leave the stack running
#
# DAYS (30) is the telemetry history, DEVICES (150) how many access points
# the agent watches and the outage storm takes down, ATLAS_LOAD_SECONDS
# (300) and ATLAS_LOAD_USERS (12) the load itself.
#
# Its own Compose project, image tag, ports and volumes, like the browser
# tests' stack, so it never touches the one you work in.
set -euo pipefail
cd "$(dirname "$0")/../.."

export COMPOSE_PROJECT_NAME=atlas-load ATLAS_IMAGE_TAG=load-test
export ATLAS_PORT=8300 ATLAS_HTTPS_PORT=8743 ATLAS_DB_PORT=5436 ATLAS_BIND=127.0.0.1
export ATLAS_ENV_FILE=./tests/Performance/.stack.env ATLAS_SERVER_NAME= ATLAS_URL=
# No backup service: a throwaway database must not fill ./backups.
export COMPOSE_PROFILES=
export DB_PASSWORD="$(node -e "console.log(require('crypto').randomBytes(16).toString('hex'))")"
DAYS="${DAYS:-30}"
DEVICES="${DEVICES:-150}"

artisan() { docker compose exec -T app php artisan "$@"; }

# Removed however the run ends, a failed seed included, unless KEEP is set.
cleanup() {
    if [ -z "${KEEP:-}" ]; then
        docker compose down --volumes --remove-orphans > /dev/null 2>&1 || true
        rm -f "$ATLAS_ENV_FILE"
    fi
}
trap cleanup EXIT

cp .env.example "$ATLAS_ENV_FILE"
# The agent below sends over plain HTTP from this machine, which reaches the
# stack through Docker's gateway: allowed for this throwaway stack only.
echo "ATLAS_AGENTS_ALLOW_PLAIN_HTTP=true" >> "$ATLAS_ENV_FILE"
docker compose down --volumes --remove-orphans > /dev/null 2>&1 || true
docker compose up -d --build --wait --wait-timeout 300

echo "Seeding the demo data, then the campus-sized estate."
artisan db:seed --force > /dev/null
artisan db:seed --class=LoadTestSeeder --force
echo "Inventing ${DAYS} days of telemetry for every simulated device."
artisan atlas:backfill-telemetry --days="$DAYS" > /dev/null
artisan atlas:recalculate-risk > /dev/null

# An agent watching $DEVICES access points, so the ingest API is measured with
# real batches. Its token is printed once, here, and only used by this run.
AGENT=$(artisan tinker --execute='
    $made = app(App\Services\Telemetry\AgentService::class)->register("Load test agent");
    $codes = App\Models\AccessPoint::query()->orderBy("id")->limit('"$DEVICES"')->pluck("code");
    App\Models\NetworkDevice::query()->whereIn("code", $codes)->update(["agent_id" => $made["agent"]->id]);
    echo json_encode(["token" => $made["token"], "devices" => $codes]);')

export ATLAS_LOAD_TOKEN=$(node -e "console.log(JSON.parse(process.argv[1]).token)" "$AGENT")
export ATLAS_LOAD_DEVICES=$(node -e "console.log(JSON.stringify(JSON.parse(process.argv[1]).devices))" "$AGENT")
export ATLAS_LOAD_ACCOUNTS=$(artisan tinker --execute='
    $demo = config("atlas.demo_users");
    echo json_encode([
        "admin" => ["email" => config("atlas.first_admin.email"), "password" => $demo["password"]],
        "engineer" => ["email" => $demo["engineer"]["email"], "password" => $demo["password"]],
        "assistant" => ["email" => $demo["assistant"]["email"], "password" => $demo["password"]],
    ]);')
export ATLAS_LOAD_URL="http://127.0.0.1:${ATLAS_PORT}"

echo "Rows: $(docker compose exec -T db psql -U atlas -d atlas -tAc "SELECT 'readings ' || (SELECT count(*) FROM telemetry_readings) || ', tickets ' || (SELECT count(*) FROM tickets) || ', inspections ' || (SELECT count(*) FROM inspections) || ', lab PCs ' || (SELECT count(*) FROM lab_pcs) || ', devices ' || (SELECT count(*) FROM network_devices)")"

status=0
node tests/Performance/response-times.mjs || status=$?

# An outage storm: every access point the agent watches stops answering.
# Measured from the batch to every incident opened and every alert sent,
# through the queue worker, as an engineer would see it.
echo
echo "Outage storm: $DEVICES devices offline at once."

# Three batches, since a device goes offline after consecutive_breach
# unreachable readings in a row. The clock starts before the first.
storm_batch() {
    node -e "
        const devices = JSON.parse(process.env.ATLAS_LOAD_DEVICES);
        const now = new Date().toISOString();
        const readings = devices.map((device, i) => ({ sequence: 9e12 + $1 * 1000 + i, device, measured_at: now, reachable: false }));
        fetch(process.env.ATLAS_LOAD_URL + '/api/v1/readings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer ' + process.env.ATLAS_LOAD_TOKEN },
            body: JSON.stringify({ sent_at: now, agent_version: 'load-test', readings }),
        }).then((r) => console.log(r.status));"
}

# Whole seconds: opened_at is stored to the second, and a fraction would
# put an incident opened in the same second before the start.
since=$(docker compose exec -T db psql -U atlas -d atlas -tAc "SELECT date_trunc('second', now())")
started=$(date +%s%3N)
for n in 0 1 2; do
    answered=$(storm_batch "$n")
    [ "$answered" = "200" ] || { echo "  batch $n answered $answered"; status=1; }
done

# Only the storm's own devices, and only incidents and alerts since it began.
storm_sql="
    WITH storm AS (SELECT id FROM network_devices WHERE code IN (SELECT jsonb_array_elements_text('${ATLAS_LOAD_DEVICES}'::jsonb)))
    SELECT
        (SELECT count(*) FROM network_devices WHERE id IN (SELECT id FROM storm) AND status = 'offline') || ' ' ||
        (SELECT count(DISTINCT d.network_device_id) FROM incident_devices d JOIN incidents i ON i.id = d.incident_id
            WHERE i.resolved_at IS NULL AND i.opened_at >= '${since}' AND d.network_device_id IN (SELECT id FROM storm)) || ' ' ||
        (SELECT count(*) FROM incident_alerts a JOIN incidents i ON i.id = a.incident_id WHERE i.opened_at >= '${since}' AND a.sent_at IS NOT NULL) || ' ' ||
        (SELECT count(*) FROM incident_alerts a JOIN incidents i ON i.id = a.incident_id WHERE i.opened_at >= '${since}' AND a.sent_at IS NULL)"

done_storm=""
for i in $(seq 1 120); do
    read -r offline covered sent pending <<< "$(docker compose exec -T db psql -U atlas -d atlas -tAc "$storm_sql")"
    if [ "${offline:-0}" -eq "$DEVICES" ] && [ "${covered:-0}" -eq "$DEVICES" ] && [ "${sent:-0}" -gt 0 ] && [ "${pending:-1}" -eq 0 ]; then
        done_storm=$(( $(date +%s%3N) - started ))
        break
    fi
    sleep 0.5
done

if [ -n "$done_storm" ]; then
    echo "  all $DEVICES offline and in incidents, ${sent} alerts sent, ${done_storm} ms after the first batch was sent"
else
    echo "  FAILED: after 60 s, ${offline:-0} of $DEVICES offline, ${covered:-0} in incidents, ${sent:-0} alerts sent, ${pending:-0} waiting"
    status=1
fi


exit "$status"
