#!/usr/bin/env bash
# Build a throwaway stack from the working tree, fill it to campus size, and
# measure response times against it (NFR-02, docs/07-testing.md).
#
#   bash tests/Performance/load-stack.sh          build, seed, measure, remove
#   KEEP=1 bash tests/Performance/load-stack.sh   leave the stack running
#
# Its own Compose project, image tag, ports and volumes, like the browser
# tests' stack, so it never touches the one you work in.
set -euo pipefail
cd "$(dirname "$0")/../.."

export COMPOSE_PROJECT_NAME=atlas-load ATLAS_IMAGE_TAG=load-test
export ATLAS_PORT=8300 ATLAS_HTTPS_PORT=8743 ATLAS_DB_PORT=5436 ATLAS_BIND=127.0.0.1
export ATLAS_ENV_FILE=./tests/Performance/.stack.env ATLAS_SERVER_NAME= ATLAS_URL=
export DB_PASSWORD="$(node -e "console.log(require('crypto').randomBytes(16).toString('hex'))")"
DAYS="${DAYS:-30}"

artisan() { docker compose exec -T app php artisan "$@"; }

cp .env.example "$ATLAS_ENV_FILE"
docker compose down --volumes --remove-orphans > /dev/null 2>&1 || true
docker compose up -d --build --wait --wait-timeout 300

echo "Seeding the demo data, then the campus-sized estate."
artisan db:seed --force > /dev/null
artisan db:seed --class=LoadTestSeeder --force
echo "Inventing ${DAYS} days of telemetry for every simulated device."
artisan atlas:backfill-telemetry --days="$DAYS" > /dev/null
artisan atlas:recalculate-risk > /dev/null

# An agent watching 40 access points, so the ingest API is measured with
# real batches. Its token is printed once, here, and only used by this run.
AGENT=$(artisan tinker --execute='
    $made = app(App\Services\Telemetry\AgentService::class)->register("Load test agent");
    $codes = App\Models\AccessPoint::query()->orderBy("id")->limit(40)->pluck("code");
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

if [ -z "${KEEP:-}" ]; then
    docker compose down --volumes --remove-orphans > /dev/null 2>&1
    rm -f "$ATLAS_ENV_FILE"
fi

exit "$status"
