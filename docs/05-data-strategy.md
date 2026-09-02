> **For the report:** [10-simulated-and-real-data.md](10-simulated-and-real-data.md) turns this file's mechanisms into the Limitations and Assumptions wording, with live row counts.

# Data Strategy
 — Mock, Real, or Both

> **Last updated:** 2026-08-30 · **Decision:** [ADR-0002](decisions/ADR-0002-telemetry-source-driver.md), [ADR-0003](decisions/ADR-0003-single-database-demo-flag.md)
>
> **Read this before writing any seeder, factory, or anything that produces data.**

---

## The question is narrower than it looks

Start by noticing what is *not* in question. Users, locations, lab PCs, checklists, inspections, tickets and shifts are all entered by people through our own interface. They are real the moment someone types them; a seeder just saves the typing.

There is exactly **one** genuinely external data source in Atlas NetCare: **Wi-Fi access point telemetry**. That is the only place the mock-versus-real question actually arises.

So we do not build an abstraction over everything. We build one, over the one thing that needs it.

## The strategy in one paragraph

One database, one schema. Telemetry enters through a swappable driver chosen by config, so switching from a simulator to live SNMP is a `.env` change plus one new class. Everything else is seeded, split into reference data that always runs and demo data that only runs when demo mode is on. Demo records carry an `is_demo` flag and simulated readings carry `source = 'simulated'`, so a single command can remove all of it and leave real data untouched.

---

## Mechanism 1 — the telemetry driver swap

### The interface

```php
// app/Services/Telemetry/TelemetrySource.php
interface TelemetrySource
{
    /** @return Collection<ReadingData> one reading per active access point */
    public function collect(): Collection;
}
```

### The binding — the swap lives here, and only here

```php
// app/Providers/AppServiceProvider.php
$this->app->bind(TelemetrySource::class, fn () => match (config('atlas.telemetry_driver')) {
    'snmp'  => new SnmpTelemetrySource(),
    default => new SimulatedTelemetrySource(),
});
```

### The consumer — driver-agnostic

```php
// app/Console/Commands/CollectTelemetry.php — runs every minute
public function handle(TelemetrySource $source, TelemetryService $service): int
{
    $service->store($source->collect());
    return self::SUCCESS;
}
```

**Nothing downstream knows which driver ran.** The dashboard controller, the risk scorer and every Blade view read `telemetry_readings` and cannot tell the difference. That is the property we are buying.

### Switching to real data

```dotenv
# .env
ATLAS_TELEMETRY_DRIVER=snmp
```

Plus implementing `SnmpTelemetrySource::collect()`. No controller, service, view, migration or test changes.

### Writing the simulator

Write it with a little care and it becomes an asset rather than a liability. Roughly sixty lines:

- A **daily curve** — quiet overnight, rising from 08:00, peaking mid-afternoon, falling after 18:00
- A **weekday/weekend** difference
- A configurable **exam-week multiplier** so you can demonstrate congestion on demand
- Per-AP **baseline variation** so some access points are naturally busier than others
- A small random chance an AP **drops offline** for a few minutes, so the auto-ticketing and alarm paths have something to fire on
- Latency and packet loss that **rise with saturation**, so the numbers are internally consistent

That last point matters more than it sounds. If latency is random noise uncorrelated with client count, the predictive module has nothing real to find and a sharp marker will notice.

---

## Mechanism 2 — two seeders, not one

| Seeder | Contains | Runs |
|---|---|---|
| `ReferenceSeeder` | The one admin account, campus locations, checklist templates and items, shift templates. The minimum a genuine deployment needs on day one. | **Always** |
| `DemoDataSeeder` | ~40 access points, ~250 lab PCs, 90 days of telemetry, ~300 tickets across every state, inspection history, sample users. All tagged `is_demo = true`. | **Only when `ATLAS_DEMO_MODE=true`** |

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

- **Factories** produce demo data — `LabPc::factory()->count(250)->create()`. The same factories power feature tests, so there is only ever one set of fixtures to maintain.
- **Seeders** produce reference data — explicit, readable, hand-written records. Never write a factory for reference data; a location called "Level 6 IT Lab 01" should appear literally in the seeder where anyone can read it.

### Seeding 90 days of telemetry

Do not loop and `create()` 1.7 million rows — it will take an hour. Build arrays and `insert()` in chunks of ~2,000, and let the simulator generate readings at a coarser interval (every 5 minutes rather than every minute) for historical backfill. Note the interval difference in the seeder comment so nobody is confused later.

---

## Mechanism 3 — tagging, so it can be removed

Two small columns do the work of an entire second environment:

| Column | On | Values |
|---|---|---|
| `source` | `telemetry_readings`, `telemetry_hourly` | `simulated` · `live` |
| `is_demo` | `users`, `lab_pcs`, `access_points`, `tickets` | boolean, set only by `DemoDataSeeder` |

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
| 1 | **AP telemetry** | Scheduled command → driver → `telemetry_readings` | **The only genuine question.** Simulated by default. |
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
- The simulator has to be written well enough to be plausible — sixty lines, but thought-through ones.
- If live SNMP access ever arrives, credential storage becomes a new problem we have not solved.

**What this buys us**

- Switching to live data is a one-line `.env` change plus one class.
- Every controller, service and view has a single code path, so there is no "works with mock data, breaks with real data" class of bug.
- Demo data is removable with one command, which is a direct answer to a likely viva question.
- The demo is reproducible: `php artisan migrate:fresh --seed` gives the same rich dataset on any machine, every time.

---

## The viva answer

> "Only one data source in our system is external, so that is the only place we abstracted. Everything else is user-entered, so mock and real data are the same shape and share one code path. Switching to live telemetry is a config change and one new class, and our demo data is removable with a single artisan command."

That answer demonstrates judgement, which is what the implementation rubric means by innovation.
