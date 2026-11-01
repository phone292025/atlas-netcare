/**
 * Agents coming back after an outage, for NFR-02: several at once, each
 * sending the readings it buffered, 500 to a batch, the most Atlas takes.
 *
 *   node tests/Performance/backlog.mjs '<json list of {token, devices}>'
 *
 * Each agent sends BATCHES batches in a row, as the real agent does, while
 * the other agents send theirs at the same time. The readings are from the
 * last few hours, so they fill history without moving a device's status.
 * Fails if a batch takes longer than 7 seconds, is refused, or has a
 * reading rejected. load-stack.sh runs it after the outage storm.
 */

const base = process.env.ATLAS_LOAD_URL ?? 'http://127.0.0.1:8300';
const agents = JSON.parse(process.argv[2] ?? '[]');
const batches = Number(process.env.BATCHES ?? 2);
const targetMs = 7000;
const perBatch = 500;

async function backlog(agent, a) {
    const times = [];
    let sequence = 1;
    // Readings a minute apart, going back from five minutes ago, every device
    // at each moment, so each batch is a few hours of one site's buffer.
    let minutesAgo = 5;

    for (let b = 0; b < batches; b++) {
        const readings = [];
        while (readings.length < perBatch) {
            const at = new Date(Date.now() - minutesAgo * 60_000).toISOString();
            for (const device of agent.devices) {
                if (readings.length === perBatch) break;
                readings.push({ sequence: sequence++, device, measured_at: at, reachable: true, latency_ms: 5, packet_loss_pct: 0, client_count: 14 });
            }
            minutesAgo++;
        }

        const started = performance.now();
        const response = await fetch(`${base}/api/v1/readings`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${agent.token}` },
            body: JSON.stringify({ sent_at: new Date().toISOString(), agent_version: 'backlog-test', readings }),
        });
        const answer = await response.json().catch(() => ({}));
        times.push(performance.now() - started);

        if (response.status !== 200 || (answer.rejected ?? []).length > 0 || (answer.stored ?? []).length !== perBatch) {
            throw new Error(`agent ${a + 1}, batch ${b + 1}: ${response.status}, stored ${(answer.stored ?? []).length}, rejected ${(answer.rejected ?? []).length}`);
        }
    }

    return times;
}

const started = performance.now();
const results = await Promise.all(agents.map(backlog));
const all = results.flat().sort((x, y) => x - y);
const slowest = all.at(-1);

console.log(`  ${agents.length} agents at once, ${batches} batches of ${perBatch} each: ${all.length * perBatch} readings stored in ${Math.round(performance.now() - started)} ms`);
console.log(`  per batch: median ${Math.round(all[Math.floor(all.length / 2)])} ms, slowest ${Math.round(slowest)} ms`);

if (slowest > targetMs) {
    console.log(`  FAILED: a batch took longer than ${targetMs} ms.`);
    process.exit(1);
}
