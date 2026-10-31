# Architecture

> **Last updated:** 2026-11-01 · **Decisions behind this:** [ADR-0001](decisions/ADR-0001-modular-monolith-no-repositories.md), [ADR-0010](decisions/ADR-0010-probe-agents-push-readings.md), [ADR-0017](decisions/ADR-0017-agent-tokens-and-ingest-api.md), [ADR-0005](decisions/ADR-0005-ajax-polling-not-websockets.md), [ADR-0006](decisions/ADR-0006-alpine-chartjs-not-livewire.md)

---

## Shape

**A modular monolith on standard Laravel MVC, with a thin service layer and exactly one abstraction.**

It was built by a capstone team of seven, each of whom had to explain it under questioning, which ruled out anything clever. It is now a solo project, and the plainness still pays. Eloquent is already a data-access layer, so there are no repositories. The single genuine abstraction is the agent, because telemetry is the only data that comes from outside the system: every reading comes from an agent watching the device, either a probe agent on a real network or the simulator inside Atlas, and both take the same path in (ADR-0010).

## Request lifecycle

```
Browser
  │  Blade HTML + Tailwind, Alpine for interactions, Chart.js for graphs
  ▼
Routes            routes/web.php grouped by role prefix, including the
  │               /api/* polling endpoints (behind `auth`, not public)
  ▼
Middleware        web group: EnsureAccountIsActive, EnsurePasswordIsChanged
  │               then auth → role:admin|engineer|assistant
  ▼
Controller        HTTP only. Validate via Form Request, authorise,
  │               hand decisions to a service, return a view or JSON.
  │               Plain admin create/edit and filtered index lists use
  │               the model directly. See "Two kinds of controller".
  ▼
Service           The business rules live here. This is what unit tests
  │               point at.
  ▼
Model             Eloquent and the query builder. Relationships, enum
  │               casts, scopes, accessors. Raw SQL is rare: see below.
  ▼
PostgreSQL        One database, one schema. Real and demo data coexist,
                  distinguished by a column.
```

### The machine API

Probe agents come in through `routes/api.php`, a separate stack with no session, cookies or CSRF. It has one route, `POST /api/v1/readings` (ADR-0017), and its middleware runs in this order:

```
throttle:agent-address   a limit per IP address, before anything is checked,
                         so a caller without a token can't keep the app busy
LimitBodySize            413 for a body over `ingest.max_kilobytes`, before
                         it is parsed
AuthenticateAgent        401 without a valid bearer token. The agent is put on
                         the request, and never becomes a signed-in user, so
                         the token opens no web screen
throttle:agent           a limit per agent
```

Both rate limiters are defined in `AppServiceProvider`, from `config('atlas.ingest')`. `ReadingIngestController` then validates the batch with `IngestReadingsRequest`, records the agent's contact and clock offset through `AgentService`, and hands the readings to `ReadingIngest`. The browser's own JSON endpoints stay in `web.php`, behind a signed-in session.

### Two kinds of controller

Decisions go to a service: ticket transitions and assignment, inspection outcomes, the roster and shift check-in, checklist versioning, telemetry and the Network monitor's controls, agent registration and tokens, risk scoring and the reports. An assistant raising a ticket and the collector raising one for an offline device both call `TicketService::open()`, so both get a reference number and an SLA due time the same way.

Simpler writes stay in the controller, after the Form Request and the route's role check, plus a policy where one exists. The admin CRUD screens for users, locations, lab PCs, access points, network devices and shift templates write the model directly. So do a few smaller actions elsewhere: creating a checklist template before `ChecklistService` writes its items, adding a ticket comment, abandoning an unfinished inspection, and creating or revoking a calendar link. This is hard rule 1's test in `AGENTS.md`: logic becomes a service when a second caller needs it, and none of these has one. The same test lets every `index` action build its own filtered query, and lets two read-only engineer screens query directly: Maintenance priority lists the scores `RiskScoringService` already stored, and Traffic logs sums hourly buckets for its one chart.

A few rules do sit in those controllers, each with one caller:

- Deleting a location, a shift template or a checklist template is refused while something still depends on it.
- Retiring a lab PC writes `status = retired` and the soft-delete stamp. Restoring it clears the stamp and sets the status to operational.
- A new account is active and must change its password on first sign-in, and an administrator cannot change their own role (`UserPolicy`).

They are the first to move into a service if a second caller appears.

### Raw SQL

Raw SQL is rare, and none of it takes request input. Most of it is aggregates in `selectRaw` (sums, averages, counts and maxima), mainly in `ReportingService`. The rest:

- a `CASE` sort in `Ticket::scopeMostUrgentFirst()`, built from `TicketPriority` weights
- timestamp bucket expressions (`SUBSTR` over `to_char` on PostgreSQL, over the column on SQLite) in `NetworkMonitorService`, built from constants
- the `INSERT … ON CONFLICT DO NOTHING RETURNING` in `TelemetryService::record()` that stores each agent reading once. Its column names come from `ReadingData::toRow()` and every value is bound
- the LATERAL lookup for each device's newest reading in `TelemetryService::latestReadings()`, which binds the ids as an array
- TimescaleDB calls in `TelemetryStorage`: job settings built from integers in `config/atlas.php`, and a refresh whose window is bound
- an `information_schema` query in `atlas:check-schema` and a `REPLACE()` update in `atlas:renumber-tickets`, both run by hand. The update takes its table and column names from a class constant and binds the values.

Every value in these is either bound with `?` or built from a constant or an enum.

### Scheduled commands

Every scheduled command enters at the **Service** layer and skips the top three entirely. That is deliberate. A command and a controller then share exactly the same business logic: `atlas:collect-telemetry` and the Network monitor's Generate sample button both run `TelemetryCollector::tick()`.

Retention, compression and the hourly summaries are not scheduled commands: they are TimescaleDB jobs, set from `config/atlas.php` by `atlas:telemetry-storage` when the app starts. `atlas:recalculate-risk` reads `RiskAssessment` directly, but only to print its summary after `RiskScoringService` has done the scoring.

---

## Folder structure

Folders that do not exist in a stock Laravel install are marked **new**.

```
app/
├── Console/Commands/                    ← scheduled jobs and maintenance tools
│   ├── CollectTelemetry.php             scheduled (see the table below)
│   ├── TelemetryStorage.php             at app start: TimescaleDB's jobs from config
│   ├── DispatchAlarms.php
│   ├── GenerateShifts.php
│   ├── MarkMissedShifts.php
│   ├── RecalculateRisk.php
│   ├── BackfillTelemetry.php            by hand — invents history so the
│   │                                    charts have data
│   ├── PurgeDemoData.php                by hand
│   └── …                                CheckSchema, MailTest, and the rest:
│                                        docs/13-code-map.md lists every file
├── Enums/                               ← NEW: backed PHP enums
│   ├── Concerns/HasValues.php           values() and options() for every enum
│   ├── Contracts/HasLabel.php           every enum must provide label()
│   ├── Contracts/HasTone.php            enums that carry semantic colour
│   ├── UserRole.php
│   ├── TicketStatus.php
│   ├── TicketPriority.php
│   ├── TicketCategory.php
│   ├── DeviceStatus.php                 lab PCs
│   ├── DeviceKind.php                   access point, switch, router…
│   ├── NetworkStatus.php                network devices
│   ├── ChecklistResult.php
│   ├── ChecklistCategory.php
│   ├── InspectionOutcome.php
│   ├── ShiftStatus.php
│   ├── AlarmType.php
│   ├── RiskBand.php
│   ├── SimulationScenario.php           demo tooling: what the simulator forces on a device
│   └── AgentContact.php                 how recently an agent was heard from
├── Http/
│   ├── Controllers/
│   │   ├── Admin/       users, locations, inventory, checklists, rosters, audit
│   │   ├── Engineer/    dashboard, tickets, traffic, risk
│   │   ├── Assistant/   shifts, inspections, tickets
│   │   ├── Api/         DashboardDataController — the polling endpoint;
│   │   │                ReadingIngestController, the agents' ingest API
│   │   └── Auth/        Breeze, with register removed
│   ├── Middleware/
│   │   ├── EnsureUserHasRole.php         ← NEW: the `role` alias
│   │   ├── EnsureAccountIsActive.php     on the web GROUP: signs out a
│   │   │                                 deactivated account
│   │   ├── EnsurePasswordIsChanged.php   on the web GROUP, not an alias
│   │   ├── IgnoreArrayQueryValues.php    GLOBAL: drops ?a[]= query values
│   │   ├── AuthenticateAgent.php         ← NEW: an agent's bearer token, on
│   │   │                                 the ingest route only
│   │   └── LimitBodySize.php             ← NEW: 413 for an oversized batch
│   └── Requests/                         one Form Request per write action
├── Models/                               19 Eloquent models
├── Observers/
│   └── AuditObserver.php                 ← NEW: writes audit_logs
├── Policies/                             per-record authorisation
├── Providers/
│   └── AppServiceProvider.php            audited models, ingest rate limits
├── Support/                              ← NEW: shared, framework-free helpers
│   ├── Tone.php                          THE tone → Tailwind mapping. One
│   │                                     method per surface. Never write a
│   │                                     match on a tone anywhere else.
│   ├── CampusClock.php                   the campus wall clock: every "what
│   │                                     day is it" question goes through it
│   └── FormField.php                     id and error-key derivation shared by
│                                         <x-form.field> and its three controls
└── Services/                             ← NEW: all business logic
    ├── Telemetry/
    │   ├── Simulator.php                 the simulator's virtual agent: one round of readings
    │   ├── SimulatedReadings.php         the model the simulator's numbers come from
    │   ├── ReadingIngest.php             one batch from a probe agent
    │   ├── AgentService.php              registration, tokens, contact, silence
    │   ├── ReadingData.php               simple DTO
    │   ├── StoredReadings.php            what record() stored, and the status changes
    │   ├── TelemetryService.php          record(): the one way readings get in
    │   ├── TelemetryCollector.php        one collection round, with its tickets and alarms
    │   ├── TelemetryBackfill.php         simulated history, and filling a collection gap
    │   ├── TelemetryStorage.php          TimescaleDB's jobs, from config
    │   ├── NetworkMonitorService.php     the Network monitor snapshot
    │   └── SimulationService.php         take a sample, force a scenario (simulated devices only)
    ├── Maintenance/
    │   ├── InspectionService.php         start, save answers, derive the outcome
    │   └── ChecklistService.php          template versioning and activation
    ├── Risk/
    │   └── RiskScoringService.php        the weighted risk model (ADR-0007)
    ├── Tickets/
    │   └── TicketService.php             the ticket state machine, and the
    │                                     only thing that may write `status`
    ├── Reports/
    │   └── ReportingService.php          the five FR-410 figures
    └── Shifts/
        ├── ShiftService.php              roster generation, check-in,
        │                                 reassignment, removal
        ├── AlarmService.php              the one writer of `alarms`
        └── ShiftCalendar.php             shifts as an iCalendar file

config/atlas.php                          ← NEW: every threshold, weight, toggle
database/
├── migrations/                           one per table, never edited after merge
├── factories/                            demo data only
└── seeders/
    ├── DatabaseSeeder.php
    ├── ReferenceSeeder.php               always runs
    └── DemoDataSeeder.php                only when demo_mode is true
resources/views/
├── layouts/          app.blade.php, guest.blade.php
├── components/       shared Blade components
├── admin/  engineer/  assistant/  auth/
routes/
├── web.php  auth.php  console.php
└── api.php                               the agents' ingest API
tests/
├── Feature/                              one folder per module
└── Unit/                                 services, especially RiskScoringService
```

Keep the tree **shallow**. Deep nesting is where a reader gets lost.

---

## Dependency rules

Dependencies point inward, towards the business rules. Each part of `app/` may use the parts listed for it, and never the code that delivers it:

| Part | May use |
|---|---|
| `Enums`, `Support` | Nothing else in `app/` |
| `Models` | Enums and Support |
| `Observers`, `Policies` | Models, Enums and Support |
| `Services` | Models, Enums and Support, and another service module only along an edge below |
| `Http`, `Console`, `View`, `Providers` | Anything above |

Nothing in `app/` uses a controller or a command. They are entry points, and logic that two of them need goes into a service they both call.

Business code, meaning the services, models, enums and support classes, never reads the request, the session or the signed-in user. The scheduler, the queue worker and the agents' ingest API have none of them, so a service that asked `auth()` for the user would get `null` there. The caller passes the user in.

The service modules use each other along three edges, which form no cycle:

| From | To | Why |
|---|---|---|
| Telemetry | Tickets | `TelemetryCollector` opens a ticket for a device that goes offline |
| Telemetry | Shifts | `TelemetryCollector` alarms the engineers when a device goes offline or degrades, and engineers and administrators when an agent goes silent |
| Tickets | Shifts | `TicketService` alarms engineers about a new critical ticket, a ticket assigned to them and a missed SLA |

Maintenance, Reports, Risk and Shifts use no other module. Every module shares the models, so these rules cover the services only. Phase 2.1 of the [roadmap](roadmap.md) replaces the two Telemetry edges with domain events ([ADR-0014](decisions/ADR-0014-incidents-and-domain-events.md)).

`tests/Unit/ArchitectureTest.php` checks all of this on every test run. A new edge between modules fails it until the edge is added to the test's list with its reason, and a listed edge that the code no longer uses fails it until it is deleted.

---

## The five hard rules

1. **No business logic in controllers.** Anything that decides, computes or changes state belongs in a service: state machines, scoring, SLA arithmetic, report aggregation. A controller validates via a Form Request, calls the service, and returns a view or JSON. **Assembling a filtered list for one screen is not business logic** and may stay in the action. The test is whether a second caller would need the same answer. The same test lets a plain admin create or edit, where validation and the role or policy check are the whole rule, write the model from the controller. `ReportController` failed it at 309 lines and became `ReportingService` on 2026-09-04.

2. **No database queries in Blade.** Views receive data as props. They never call `::all()`, `::where()`, or traverse a relationship that triggers a query. It is the most common cause of N+1 queries, and `ListScreensDoNotNPlusOneTest` counts the queries on the list screens.

3. **No magic numbers.** Every threshold, weight, SLA hour and interval lives in `config/atlas.php`. If you are about to type a number that represents a business rule, put it in config instead. When somebody asks "where did 120 ms come from?", the answer should be one file.

4. **One migration per table, never edited after merge.** Schema changes go in a *new* migration. Editing a merged migration silently breaks every database that already ran it and produces the "it works on my machine" bug that costs a whole evening.

5. **Small commits on a branch, each one green.** Work happens on a branch, in commits that each pass the checks on their own. [roadmap.md](roadmap.md) names the branch in use.

---

## Authorisation

Two layers, and they do different jobs:

| Layer | Answers | Implementation |
|---|---|---|
| **Middleware** | "May this account be here at all, and may this role reach this area?" | `EnsureAccountIsActive` and `EnsurePasswordIsChanged` run on the whole `web` group, because a control you have to remember to apply is one somebody will forget. `EnsureUserHasRole` is applied to route groups. |
| **Policy** | "May this specific user act on this specific record?" | Laravel Policies, e.g. `TicketPolicy::close()` |

Never use middleware for record-level checks, and never scatter `if ($user->role === ...)` through controllers. If you find yourself writing that condition, it belongs in a Policy.

The ingest API has no users and no roles. An agent is authorised by its token, and `ReadingIngest` accepts a reading only for a device whose `agent_id` is that agent. An unknown device and another agent's device get the same refusal, so a token can't be used to list device codes.

```php
Route::middleware(['auth'])->group(function () {
    Route::get('/', DashboardRedirect::class);          // role → correct home
    Route::resource('tickets', TicketController::class); // shared, scoped by Policy

    Route::middleware('role:assistant')->prefix('assistant')->name('assistant.')->group(/* … */);
    Route::middleware('role:engineer')->prefix('engineer')->name('engineer.')->group(/* … */);
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(/* … */);
});
```

---

## Configuration

`config/atlas.php` is the project's single tuning surface. Every business rule that is a number lives here.

> Formatting note: write these as `'key' => value` with a single space. Laravel
> Pint's default preset normalises aligned `=>` arrows, so do not fight the
> formatter on it. Run `vendor/bin/pint` before committing.

An excerpt. The file itself explains every value.

```php
return [
    'gap_fill_hours' => 24,
    'demo_mode' => (bool) env('ATLAS_DEMO_MODE', true),
    'first_admin' => [/* name, email: atlas:create-admin's defaults */],
    'demo_users' => [/* password, and one account per role */],
    'poll_interval' => (int) env('ATLAS_POLL_INTERVAL', 20),          // seconds

    'thresholds' => [
        'latency_ms' => 120,
        'packet_loss_pct' => 5.0,
        'client_saturation' => 0.85,
        'consecutive_breach' => 3,
        'offline_after_min' => 5,
        'degraded_alert_cooldown_min' => 60,
    ],

    // One set per asset kind, each summing to 1.0.
    'risk_weights' => [
        'lab_pc' => ['age' => 0.25, 'fault_frequency' => 0.30, 'critical_fail_rate' => 0.25, 'days_since_inspect' => 0.20],
        'access_point' => ['latency_trend' => 0.30, 'packet_loss_trend' => 0.25, 'offline_events' => 0.30, 'client_saturation' => 0.15],
    ],
    'risk_inputs' => [/* the ceilings that turn a measurement into 0..1 */],

    'risk_bands' => ['low' => 0, 'medium' => 40, 'high' => 65, 'critical' => 85],

    'sla_hours' => ['critical' => 4, 'medium' => 24, 'low' => 72],
    'new_ticket_alert_from' => env('ATLAS_NEW_TICKET_ALERT_FROM', 'critical'),

    // The ingest API (ADR-0017).
    'ingest' => [
        'silent_after_minutes' => 3,
        'max_readings' => 500,
        'max_kilobytes' => 256,
        'per_agent_per_minute' => 60,
        'per_address_per_minute' => 120,
        'future_minutes' => 5,
        'clock_drift_seconds' => 30,
    ],

    // Applied as TimescaleDB policies by atlas:telemetry-storage.
    'retention' => [
        'raw_days' => 90,
        'aggregate_months' => 12,
        'chunk_days' => 1,
        'compress_after_days' => 7,
        'late_reading_days' => 7,
        'hourly_refresh_minutes' => 30,
    ],

    'shifts' => [/* check-in grace, reminder lead, round interval */],
    'calendar' => [/* campus timezone, reminder, subscription toggle */],

    // Sidebar entries per role, built once and filtered by the signed-in
    // user's role. See docs/06-ui-conventions.md.
    'navigation' => [
        'assistant' => [/* label, route, icon */],
        'engineer' => [/* … */],
        'admin' => [/* … */],
    ],
];
```

`config/atlas.php` is a **shared file**. Every module reads it, so check who uses a value before changing it.

---

## Scheduled tasks

| Command | Frequency | Does |
|---|---|---|
| `atlas:collect-telemetry` | every minute | The simulator's round: one reading per device it watches. Then the silence sweeps: a device nobody has heard from for `offline_after_min` goes Offline, and a silent agent's devices go Unknown. A ticket and an alert when a device goes offline, an alert when one degrades, and one alert per silent agent |
| `atlas:dispatch-alarms` | every minute | Sends alarms past their trigger time; raises SLA breaches |
| `atlas:recalculate-risk` | daily 02:00 | Rescores every asset, writes a `risk_assessments` row. Out of hours, so the ranking doesn't reorder under somebody reading it |
| `atlas:purge-demo-data` | never — run by hand | Removes every demonstration row, leaving reference data and real records |
| `atlas:renumber-tickets` | never — run by hand | Repairs a database seeded with random ticket references: sequential per year, references rewritten, `--dry-run` first |
| `atlas:mark-missed-shifts` | every five minutes | Marks ended shifts nobody checked into as missed, so the roster stops offering to reassign them |
| `atlas:generate-shifts` | weekly, Sunday 18:00 | Materialises next week's shifts from templates |

Times are on the campus clock. Under Docker the `scheduler` service runs `php artisan schedule:work` and drives all five scheduled commands. Without Docker, run the same command in a terminal.

`atlas:telemetry-storage` is not scheduled. The app container runs it on every start, after the migrations, to set TimescaleDB's compression, retention and hourly refresh jobs from `config/atlas.php`.

---

## Testing approach

| Kind | Target | Where |
|---|---|---|
| **Unit** | Pure logic: `RiskScoringService`, the enums, `Tone`, the config's invariants | `tests/Unit/` |
| **Feature** | Every service against a real database, and every screen through HTTP: a happy path, an empty state and an authorisation failure | `tests/Feature/{Module}/` |
| **Structure** | The [dependency rules](#dependency-rules), read from the imports in `app/` | `tests/Unit/ArchitectureTest.php` |
| **Manual / UAT** | 4 documented sessions | [07-testing.md](07-testing.md) |

Use the same factories that power `DemoDataSeeder`. Do not maintain a second set of test fixtures.

---

## What we deliberately did not do

Recorded here so nobody re-proposes them.

| Not doing | Why |
|---|---|
| Repository pattern over Eloquent | 34 extra files so that one of them could be swapped. Abstract what varies. [ADR-0001](decisions/ADR-0001-modular-monolith-no-repositories.md) |
| WebSockets / Laravel Reverb | A persistent server process and a real debugging burden, for a dashboard whose data changes once a minute. [ADR-0005](decisions/ADR-0005-ajax-polling-not-websockets.md) |
| Livewire or Inertia | A second mental model on top of Laravel, for a capstone team of seven learning Laravel. [ADR-0006](decisions/ADR-0006-alpine-chartjs-not-livewire.md) |
| Separate mock database | Doubles migrations, breaks foreign keys across the boundary, schemas drift. [ADR-0003](decisions/ADR-0003-single-database-demo-flag.md) |
| Machine learning for risk scoring | Nobody can say why a model scored an asset the way it did. Weighted factors show their working. [ADR-0007](decisions/ADR-0007-weighted-risk-not-ml.md) |
| Microservices, event sourcing, CQRS | Not appropriate at this scale by any measure. |
