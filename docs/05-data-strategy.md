> [10-simulated-and-real-data.md](10-simulated-and-real-data.md) lists what is simulated and what is real, with live row counts.

# Data Strategy
 — Mock, Real, or Both

> **Last updated:** 2026-11-01 · **Decision:** [ADR-0010](decisions/ADR-0010-probe-agents-push-readings.md) (which superseded ADR-0002), [ADR-0003](decisions/ADR-0003-single-database-demo-flag.md)
>
> **Read this before writing any seeder, factory, or anything that produces data.**

---

## The question is narrower than it looks

Start by noticing what is *not* in question. Users, locations, lab PCs, checklists, inspections, tickets and shifts are all entered by people through our own interface. They are real the moment someone types them; a seeder just saves the typing.

There is exactly **one** genuinely external data source in Atlas NetCare: **Wi-Fi access point telemetry**. That is the only place the mock-versus-real question actually arises.

So we do not build an abstraction over everything. We build one, over the one thing that needs it: every reading comes from an agent.

## The strategy in one paragraph

One database, one schema. Every reading comes from an agent watching the device: a probe agent on a real network, or the simulator, a virtual agent inside Atlas. Both take the same path into the database, so moving a device from one to the other is a change to its **Watched by** field. Everything else is seeded, split into reference data that always runs and demo data that only runs when demo mode is on. Demo records carry an `is_demo` flag and simulated readings carry `source = 'simulated'`, so a single command can remove all of it and leave real data untouched.

---

## Mechanism 1 — every reading comes from an agent

Each network device has an `agent_id`: the agent that watches it. An agent may report only its own devices.

- **A probe agent** runs on a machine inside a real network and sends batches to `POST /api/v1/readings` with its token (ADR-0017). Its readings are stored with `source = 'live'`.
- **The simulator** is a virtual agent: an `agents` row with `is_virtual = true` and no token, so nothing outside Atlas can speak for it. Each minute the scheduler's collection round asks `SimulatedReadings` for a reading per device it watches, and `Simulator::round()` hands them to `TelemetryService::record()`. Its readings are stored with `source = 'simulated'`.

```php
// app/Services/Telemetry/Simulator.php: one round, every minute
public function round(): StoredReadings
{
    $devices = $this->watched()->get();
    $result = $this->telemetry->record($this->readingsAt($devices, now()));
    // ...
}
```

`TelemetryService::record()` is the one way in. The ingest API calls it too. It stores each reading under the unique key `(agent_id, sequence, recorded_at)`, classifies it, and moves the device's status only when the reading is newer than the one it has. The simulator numbers each reading from the second it was measured at and the device, so a backfill run twice stores nothing new, the same way a batch an agent sends twice is stored once.

**Nothing downstream knows which agent sent a reading.** The dashboard, the risk scorer and every Blade view read `telemetry_readings` and cannot tell the difference. That is the property we are buying.

### Moving a device to real data

Register a probe agent under **Agents**, then set **Watched by** on the device. The simulator stops measuring it, and only what the agent measures is stored from then on. The simulation controls and the backfill refuse devices a real agent watches: they would put invented numbers in front of an engineer.

### Writing the simulator

Write it with a little care and it becomes an asset rather than a liability. `SimulatedReadings` models:

- A **daily curve** — quiet overnight, rising from 08:00, peaking mid-afternoon, falling after 18:00
- A **weekday/weekend** difference
- **Forced scenarios** (congestion, outage, feed interruption) from the Network monitor, so you can demonstrate a fault on demand
- Per-AP **baseline variation** so some access points are naturally busier than others
- A small random chance an AP **drops offline** for a few minutes, so the auto-ticketing and alarm paths have something to fire on
- Latency and packet loss that **rise with saturation**, so the numbers are internally consistent

That last point matters more than it sounds. If latency is random noise uncorrelated with client count, the predictive module has nothing real to find.

---

## Mechanism 2 — two seeders, not one

| Seeder | Contains | Runs |
|---|---|---|
| `ReferenceSeeder` | Campus locations, checklist templates and items, shift templates. No account: `atlas:create-admin` makes the first administrator. The minimum a genuine deployment needs on day one. | **Always** |
| `DemoDataSeeder` | 24 access points, a core router and one switch per location, 80 lab PCs, 14 days of telemetry, 43 tickets, 25 inspections, and one demo account per role plus a second assistant. All tagged `is_demo = true`, and every network device watched by the simulator. | **Only when `ATLAS_DEMO_MODE=true`** |

```php
// database/seeders/DatabaseSeeder.php
public function run(): void
{
    $this->call(ReferenceSeeder::class);

    if (config('atlas.demo_mode')) {
        $this->call(DemoDataSeeder::class);
    }
}
```

### Factories vs seeders

- **Factories** produce demo data, such as `AccessPoint::factory()->demo()->create()`. The same factories power feature tests, so there is only ever one set of fixtures to maintain.
- **Seeders** produce reference data — explicit, readable, hand-written records. Never write a factory for reference data; a location called "Level 6 IT Lab 01" should appear literally in the seeder where anyone can read it.

### Seeding 14 days of telemetry

The seeder writes raw readings every 5 minutes rather than every minute, about 97,000 rows for 24 access points, bulk inserted in chunks of 1,000. Looping over `create()` would take minutes for nothing. It writes no hourly rows: the hourly summaries are a continuous aggregate the database derives from the readings, so the seeder refreshes it over the window it wrote. Two weeks is the window the access point risk trends read.

---

## Mechanism 3 — tagging, so it can be removed

Two small columns do the work of an entire second environment:

| Column | On | Values |
|---|---|---|
| `source` | `telemetry_readings`, and the `telemetry_hourly` aggregate groups by it | `simulated` · `live` |
| `is_demo` | `users`, `lab_pcs`, `network_devices`, `tickets` | boolean, set only by `DemoDataSeeder` |

Then one command answers "how do we get rid of the fake data?":

```bash
php artisan atlas:purge-demo-data --force
```

It is meant to delete every row flagged `is_demo` plus every reading with `source = 'simulated'`, leaving reference data and anything a real user created untouched.

**Built 2026-09-02.** It also takes `--dry-run`. Most of the work turned out to be association rather than flags: only four tables carry `is_demo`, so inspections, shifts, alarms, comments, risk assessments and audit rows each have to be found through whatever they belong to. It **refuses** if a real record points at a demonstration asset — `tickets.ticketable` is polymorphic, so no foreign key stops that, and a dangling reference renders as a blank column rather than as the data loss it is.

---

## Where data enters the system

Five entry points. Know which one you are working on.

| # | Entry point | Mechanism | Mock or real? |
|---|---|---|---|
| 1 | **Network telemetry** | A probe agent's batch to `POST /api/v1/readings`, or the simulator's round each minute, both through `TelemetryService::record()` into `telemetry_readings` | **The only genuine question.** Simulated by default. |
| 2 | **Inspections** | Assistant UI form | Real when a user types it; seeded for demo history |
| 3 | **Tickets** | Assistant or engineer UI, or auto-raised by the system on AP offline | Real when a user types it; seeded for demo |
| 4 | **Inventory, locations, users** | Admin UI | Real when a user types it; seeded for demo |
| 5 | **Shifts** | Generated from templates by scheduled command, overridable by admin | Real; templates are reference data |

---

## Four things not to do

| ❌ | Why not |
|---|---|
| **A second "mock" database** | Doubles migrations, breaks foreign keys across the boundary, and guarantees the two schemas drift apart. |
| **Repository interfaces over every model** | 34 extra files so that one of them can be swapped. Abstract the thing that varies, not the thing that doesn't. |
| **Hardcoded arrays in controllers or Blade** | Fast on day one, impossible to replace on day sixty, and visibly a shortcut to anyone reading the code. |
| **A global Eloquent scope hiding demo rows** | Sounds tidy, then silently hides data during a demo and costs someone an afternoon. Filter explicitly on the few screens that need it. |

---

## Trade-offs, honestly

**What this costs us**

- An `is_demo` column on four tables that a production system would not need.
- The simulator has to be written well enough to be plausible.
- The Go agent (roadmap task 1.7) will need SNMP community strings for the devices it checks. They go in the agent's own config file, so Atlas never holds them, and keeping that file safe is the agent machine owner's job.

**What this buys us**

- Moving a device to live data is a change to its **Watched by** field, once a probe agent is registered.
- Every controller, service and view has a single code path, so there is no "works with mock data, breaks with real data" class of bug.
- Demo data is removable with one command.
- The demo is reproducible: `php artisan migrate:fresh --seed` gives the same rich dataset on any machine, every time.

---

## In one paragraph

> Only one data source in Atlas is external, network telemetry, so that is the only place with two producers. Everything else is user-entered, so mock and real data are the same shape and share one code path. A probe agent and the simulator both send readings through `TelemetryService::record()`, a device moves between them by its **Watched by** field, and demo data is removable with a single artisan command.
