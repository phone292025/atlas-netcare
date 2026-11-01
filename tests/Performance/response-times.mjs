/**
 * Response times under load, for NFR-02 (every page and API call under 7 s).
 *
 * Signs in a number of people at once, each with their own session, and has
 * each one open its role's heaviest screens in turn for a fixed time, as
 * fast as the server answers. Every fourth step an assistant raises a ticket
 * through the form, so writes and the ticket's alerts run under the load
 * too. A time is measured from sending the request until the whole body has
 * arrived. The agent API is measured too, with batches of readings for
 * simulated devices.
 *
 * Run it against a throwaway stack seeded with LoadTestSeeder, never the
 * stack you work in: it signs in with the demo password and writes
 * readings. docs/07-testing.md, "Response times", has the steps.
 *
 *   node tests/Performance/response-times.mjs
 *
 * Settings come from the environment:
 *   ATLAS_LOAD_URL       the stack, default http://127.0.0.1:8300
 *   ATLAS_LOAD_ACCOUNTS  JSON {admin, engineer, assistant: {email, password}}
 *   ATLAS_LOAD_TOKEN     an agent token, to measure the ingest API as well
 *   ATLAS_LOAD_DEVICES   JSON list of device codes that agent watches
 *   ATLAS_LOAD_USERS     people at once, default 12
 *   ATLAS_LOAD_SECONDS   how long, default 300
 *
 * Prints one row per address and exits 1 if any request failed or any
 * address's slowest time passed the target.
 */

const base = process.env.ATLAS_LOAD_URL ?? 'http://127.0.0.1:8300';
const accounts = JSON.parse(process.env.ATLAS_LOAD_ACCOUNTS ?? '{}');
const users = Number(process.env.ATLAS_LOAD_USERS ?? 12);
const seconds = Number(process.env.ATLAS_LOAD_SECONDS ?? 300);
const targetMs = 7000;

const screens = {
    engineer: [
        '/engineer/dashboard', '/engineer/monitor', '/api/monitor', '/engineer/aps', '/engineer/traffic',
        '/engineer/tickets', '/engineer/tickets?outstanding=1', '/engineer/risk', '/admin/reports', '/api/dashboard',
    ],
    admin: ['/admin/monitor', '/admin/lab-pcs', '/admin/aps', '/admin/devices', '/admin/audit', '/admin/users', '/admin/roster'],
    assistant: ['/assistant/shifts/today', '/assistant/inspections', '/assistant/tickets', '/assistant/tickets/create', '/assistant/monitor'],
};

/** A session of its own: cookies kept between requests, as a browser does. */
class Session {
    cookies = new Map();

    async request(path, options = {}) {
        const headers = { ...options.headers, Cookie: [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ') };
        const started = performance.now();
        const response = await fetch(base + path, { ...options, headers, redirect: 'manual' });
        const body = await response.text();
        const ms = performance.now() - started;

        for (const cookie of response.headers.getSetCookie()) {
            const [pair] = cookie.split(';');
            const at = pair.indexOf('=');
            this.cookies.set(pair.slice(0, at), pair.slice(at + 1));
        }

        return { status: response.status, body, ms, location: response.headers.get('location') };
    }

    async signIn({ email, password }) {
        const form = await this.request('/login');
        const token = form.body.match(/name="_token" value="([^"]+)"/)?.[1];
        const done = await this.request('/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ _token: token, email, password }),
        });

        if (done.status !== 302 || done.location?.includes('/login')) {
            throw new Error(`Signing in as ${email} failed with ${done.status}.`);
        }
    }
}

const times = new Map();

function record(label, result, ok) {
    const entry = times.get(label) ?? { ms: [], failed: 0 };
    entry.ms.push(result.ms);
    if (!ok) entry.failed++;
    times.set(label, entry);
}

async function person(role, until) {
    const session = new Session();
    await session.signIn(accounts[role]);

    for (let i = 0; performance.now() < until; i++) {
        if (role === 'assistant' && i % 4 === 3) {
            await raiseTicket(session, i);
            continue;
        }

        const path = screens[role][i % screens[role].length];
        const result = await session.request(path, path.startsWith('/api/') ? { headers: { Accept: 'application/json' } } : {});
        record(`${role.padEnd(9)} GET ${path}`, result, result.status === 200);
    }
}

/** The raise-ticket form, filled in and sent: a write, an audit row, alerts. */
async function raiseTicket(session, i) {
    const form = await session.request('/assistant/tickets/create');
    const token = form.body.match(/name="_token" value="([^"]+)"/)?.[1];
    const machine = form.body.match(/<select[^>]*name="ticketable_id"[\s\S]*?<option value="(\d+)"/)?.[1];
    const result = await session.request('/assistant/tickets', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            _token: token, title: `Load test ticket ${i}`, description: 'Raised by the response-time measurement.',
            category: 'hardware', priority: 'low', ticketable_type: 'lab_pc', ticketable_id: machine ?? '',
        }),
    });
    // A raised ticket redirects to itself; a refused one back to the form.
    record('assistant POST /assistant/tickets', result, result.status === 302 && /\/assistant\/tickets\/\d+$/.test(result.location ?? ''));
}

async function agent(until) {
    const devices = JSON.parse(process.env.ATLAS_LOAD_DEVICES ?? '[]');
    let sequence = Date.now();

    while (performance.now() < until) {
        const now = new Date().toISOString();
        const readings = devices.map((device) => ({
            sequence: sequence++, device, measured_at: now, reachable: true, latency_ms: 4, packet_loss_pct: 0, client_count: 12,
        }));
        const result = await new Session().request('/api/v1/readings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${process.env.ATLAS_LOAD_TOKEN}` },
            body: JSON.stringify({ sent_at: now, agent_version: 'load-test', readings }),
        });
        record(`agent     POST /api/v1/readings (${devices.length})`, result, result.status === 200);
        // One batch a second, about what one agent watching these devices sends.
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
}

function percentile(sorted, p) {
    return sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))];
}

const roles = ['engineer', 'admin', 'assistant'];
const until = performance.now() + seconds * 1000;
const work = Array.from({ length: users }, (_, i) => person(roles[i % roles.length], until));

if (process.env.ATLAS_LOAD_TOKEN) {
    work.push(agent(until));
}

await Promise.all(work);

let failed = false;
const rows = [...times].sort(([a], [b]) => a.localeCompare(b)).map(([label, { ms, failed: errors }]) => {
    const sorted = [...ms].sort((a, b) => a - b);
    const max = sorted.at(-1);
    failed ||= errors > 0 || max > targetMs;

    return `${label.padEnd(58)} ${String(ms.length).padStart(5)} ${percentile(sorted, 50).toFixed(0).padStart(7)} ${percentile(sorted, 95).toFixed(0).padStart(7)} ${max.toFixed(0).padStart(7)} ${String(errors).padStart(6)}`;
});

console.log(`${users} people at once for ${seconds} s against ${base}. Times in ms.\n`);
console.log(`${'Request'.padEnd(58)} ${'n'.padStart(5)} ${'p50'.padStart(7)} ${'p95'.padStart(7)} ${'max'.padStart(7)} ${'failed'.padStart(6)}`);
console.log(rows.join('\n'));
console.log(failed ? `\nFAILED: a request failed or took longer than ${targetMs} ms.` : `\nEvery request answered, all under ${targetMs} ms.`);
process.exit(failed ? 1 : 0);
