# Architecture

> **Last updated:** 2026-08-28 · **Decisions behind this:** [ADR-0001](decisions/ADR-0001-modular-monolith-no-repositories.md), [ADR-0002](decisions/ADR-0002-telemetry-source-driver.md), [ADR-0005](decisions/ADR-0005-ajax-polling-not-websockets.md), [ADR-0006](decisions/ADR-0006-alpine-chartjs-not-livewire.md)

---

## Shape

**A modular monolith on standard Laravel MVC, with a thin service layer and exactly one abstraction.**

Seven students over eight weeks need an architecture each of them can explain under questioning. That rules out anything clever. Eloquent is already a data-access layer, so there are no repositories. The single genuine abstraction is `TelemetrySource`, because access point telemetry is the only data that comes from outside the system.

## Request lifecycle

```
Browser
  │  Blade HTML + Tailwind, Alpine for interactions, Chart.js for graphs
  ▼
Routes            routes/web.php grouped by role prefix
  │               routes/api.php for the dashboard polling endpoint
  ▼
Middleware        auth → role:admin|engineer|assistant
  │               nothing reaches a controller without a verified role
  ▼
Controller        HTTP only. Validate via Form Request, call one service
  │               method, return a view or JSON. Target: under 15 lines.
  ▼
Service           ALL business logic lives here. This is what unit tests
  │               point at.
  ▼
Model             Eloquent. Relationships, enum casts, scopes, accessors.
  │               No raw SQL anywhere.
  ▼
MySQL             One database, one schema. Real and demo data coexist,
                  distinguished by a column.
```

Scheduled commands enter at the **Service** layer and skip the top three entirely. That is deliberate — it means a command and a controller share exactly the same business logic.

---

## Folder structure

Folders that do not exist in a stock Laravel install are marked **new**.

```
app/
├── Console/Commands/                    ← scheduled jobs
│   ├── CollectTelemetry.php
│   ├── AggregateTelemetry.php
│   ├── PruneTelemetry.php
│   ├── BackfillTelemetry.php            by hand, not scheduled — invents
│   │                                    history so the charts have data
│   ├── RecalculateRiskScores.php
│   ├── DispatchDueAlarms.php
│   ├── GenerateShifts.php
│   └── PurgeDemoData.php
├── Enums/                               ← NEW: backed PHP enums
│   ├── Concerns/HasValues.php           values() and options() for every enum
│   ├── Contracts/HasLabel.php           every enum must provide label()
│   ├── Contracts/HasTone.php            enums that carry semantic colour
│   ├── UserRole.php
│   ├── TicketStatus.php
│   ├── TicketPriority.php
│   ├── TicketCategory.php
│   ├── DeviceStatus.php
│   ├── ApStatus.php
│   ├── ChecklistResult.php
│   ├── ChecklistCategory.php
│   ├── InspectionOutcome.php
│   ├── ShiftStatus.php
│   ├── AlarmType.php
│   └── RiskBand.php
├── Http/
│   ├── Controllers/
│   │   ├── Admin/       users, locations, inventory, checklists, rosters, audit
│   │   ├── Engineer/    dashboard, tickets, traffic, risk
│   │   ├── Assistant/   shifts, inspections, tickets
│   │   ├── Api/         DashboardDataController — the polling endpoint
│   │   └── Auth/        Breeze, with register removed
│   ├── Middleware/
│   │   └── EnsureUserHasRole.php         ← NEW
│   └── Requests/                         one Form Request per write action
├── Models/                               17 Eloquent models
├── Observers/
│   └── AuditObserver.php                 ← NEW: writes audit_logs
├── Policies/                             per-record authorisation
├── Providers/
│   └── AppServiceProvider.php            binds TelemetrySource
├── Support/                              ← NEW: shared, framework-free helpers
│   ├── Tone.php                          THE tone → Tailwind mapping. One
│   │                                     method per surface. Never write a
│   │                                     match on a tone anywhere else.
│   └── FormField.php                     id and error-key derivation shared by
│                                         <x-form.field> and its three controls
└── Services/                             ← NEW: all business logic
    ├── Telemetry/
    │   ├── TelemetrySource.php           interface
    │   ├── SimulatedTelemetrySource.php
    │   ├── SnmpTelemetrySource.php       stub until real access exists
    │   ├── ReadingData.php               simple DTO
    │   └── TelemetryService.php
    ├── Maintenance/
    │   ├── InspectionService.php         start, save answers, derive the outcome
    │   ├── ChecklistService.php          template versioning and activation
    │   └── RiskScoringService.php
    ├── Tickets/
    │   └── TicketService.php             the ticket state machine, and the
    │                                     only thing that may write `status`
    └── Shifts/
        ├── ShiftService.php
        └── AlarmService.php

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
├── web.php  api.php  console.php
tests/
├── Feature/                              one folder per module
└── Unit/                                 services, especially RiskScoringService
```

Keep the tree **shallow**. Deep nesting is where student projects lose people.

---

## The five hard rules

1. **No business logic in controllers.** A controller validates via a Form Request, calls one service method, and returns a view or JSON. If an action exceeds ~15 lines, the excess belongs in a service.

2. **No database queries in Blade.** Views receive data as props. They never call `::all()`, `::where()`, or traverse a relationship that triggers a query. This is the single most common cause of N+1 problems in student projects, and it will be visible to anyone who opens the debug bar during your demo.

3. **No magic numbers.** Every threshold, weight, SLA hour and interval lives in `config/atlas.php`. If you are about to type a number that represents a business rule, put it in config instead. When a marker asks "where did 120 ms come from?", the answer should be one file.

4. **One migration per table, never edited after merge.** Schema changes go in a *new* migration. Editing a merged migration silently breaks every teammate's local database and produces the "it works on my machine" bug that costs a whole evening.

5. **One module, one owner, one branch.** Check [modules/README.md](modules/README.md) before editing a file. Do not refactor another module's code without telling its owner.

---

## Authorisation

Two layers, and they do different jobs:

| Layer | Answers | Implementation |
|---|---|---|
| **Middleware** | "May this role reach this area at all?" | `EnsureUserHasRole`, applied to route groups |
| **Policy** | "May this specific user act on this specific record?" | Laravel Policies, e.g. `TicketPolicy::close()` |

Never use middleware for record-level checks, and never scatter `if ($user->role === ...)` through controllers. If you find yourself writing that condition, it belongs in a Policy.

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
> Pint's default preset normalises aligned `=>` arrows, and this is the file the
> whole team edits — do not fight the formatter on it. Run `vendor/bin/pint`
> before committing.

```php
return [
    'telemetry_driver' => env('ATLAS_TELEMETRY_DRIVER', 'simulated'), // simulated | snmp
    'demo_mode' => (bool) env('ATLAS_DEMO_MODE', true),
    'poll_interval' => (int) env('ATLAS_POLL_INTERVAL', 20),          // seconds

    'thresholds' => [
        'latency_ms' => 120,
        'packet_loss_pct' => 5.0,
        'client_saturation' => 0.85,
        'consecutive_breach' => 3,
        'offline_after_min' => 5,
    ],

    'risk_weights' => [
        'age' => 0.25,
        'fault_frequency' => 0.30,
        'critical_fail_rate' => 0.25,
        'days_since_inspect' => 0.20,
    ],

    'risk_bands' => ['low' => 0, 'medium' => 40, 'high' => 65, 'critical' => 85],

    'sla_hours' => ['critical' => 4, 'medium' => 24, 'low' => 72],

    'retention' => ['raw_days' => 90, 'aggregate_months' => 12],

    // Sidebar entries per role, built once and filtered by the signed-in
    // user's role. See docs/08-ui-conventions.md.
    'navigation' => [
        'assistant' => [/* label, route, icon */],
        'engineer' => [/* … */],
        'admin' => [/* … */],
    ],
];
```

`config/atlas.php` is a **shared file**. Changes to it are announced in the team channel, because a merge conflict here blocks everyone.

---

## Scheduled tasks

| Command | Frequency | Does |
|---|---|---|
| `atlas:collect-telemetry` | every minute | One reading per active AP, through the current driver |
| `atlas:dispatch-alarms` | every minute | Sends alarms past their trigger time; raises SLA breaches |
| `atlas:aggregate-telemetry` | hourly | Rolls raw readings into `telemetry_hourly` |
| `atlas:recalculate-risk` | daily 02:00 | Rescores every asset, writes a `risk_assessments` row — **planned, arrives with M7** |

| `atlas:generate-shifts` | weekly, Sunday | Materialises next week's shifts from templates |
| `atlas:prune-telemetry` | weekly | Drops raw readings past retention |

Locally, one Windows Task Scheduler entry running `php artisan schedule:run` every minute drives all six. **Document this in the Installation Guide** for the report appendix — graders do check.

---

## Testing approach

| Kind | Target | Where |
|---|---|---|
| **Unit** | Services with real logic — `RiskScoringService` above all, plus `TicketService` state transitions and `TelemetryService` classification | `tests/Unit/` |
| **Feature** | One happy path and one authorisation failure per controller | `tests/Feature/{Module}/` |
| **Manual / UAT** | 4 documented sessions, required by the guidelines | [10-testing.md](10-testing.md) |

Use the same factories that power `DemoDataSeeder`. Do not maintain a second set of test fixtures.

---

## What we deliberately did not do

Recorded here so nobody re-proposes them, and so the viva answer is ready.

| Not doing | Why |
|---|---|
| Repository pattern over Eloquent | 34 extra files so that one of them could be swapped. Abstract what varies. [ADR-0001](decisions/ADR-0001-modular-monolith-no-repositories.md) |
| WebSockets / Laravel Reverb | A persistent server process and a real debugging burden, for a dashboard whose data changes once a minute. [ADR-0005](decisions/ADR-0005-ajax-polling-not-websockets.md) |
| Livewire or Inertia | A second mental model on top of Laravel, for seven people learning Laravel. [ADR-0006](decisions/ADR-0006-alpine-chartjs-not-livewire.md) |
| Separate mock database | Doubles migrations, breaks foreign keys across the boundary, schemas drift. [ADR-0003](decisions/ADR-0003-single-database-demo-flag.md) |
| Machine learning for risk scoring | Cannot be explained in a viva. Weighted factors can. [ADR-0007](decisions/ADR-0007-weighted-risk-not-ml.md) |
| Microservices, event sourcing, CQRS | Not appropriate at this scale by any measure. |
