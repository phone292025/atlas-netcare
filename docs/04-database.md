# Database Design

> **Last updated:** 2026-11-01 · **Status:** describes the 1.0 schema, on PostgreSQL 16 with TimescaleDB since 2026-10-22 ([ADR-0011](decisions/ADR-0011-postgresql-timescaledb.md)). Phase 1 of the [roadmap](roadmap.md) adds devices, agents and hypertables
>
> **Rule:** a schema change updates this file in the *same commit* as the migration.

17 domain tables in three clusters, plus Laravel's own infrastructure tables (`sessions`, `password_reset_tokens`, `cache`, `jobs`).

---

## Conventions

Apply these everywhere. They are not negotiable per-module.

| Concern | Convention | Why |
|---|---|---|
| Primary key | `$table->id()` — unsigned `bigint`, auto-increment | Laravel default; every relationship helper assumes it |
| Foreign key | `foreignId('x_id')->constrained()` with an explicit delete rule | Integrity enforced by the database, not application hope |
| Delete rule | `restrictOnDelete()` for reference data (locations, users); `cascadeOnDelete()` for owned children (inspection_results, ticket_comments) | You can never orphan an inspection by deleting a lab |
| Enumerations | `string` column + PHP backed enum + `$casts` | Adding a status later is a code change, not a risky `ALTER TABLE`. [ADR-0004](decisions/ADR-0004-string-columns-not-mysql-enum.md) |
| Percentages | `decimal(5,2)` | Exact. Never `float` for a number a user reads |
| Money | *(none in this system)* | — |
| Booleans | `is_` or `has_` prefix | Reads as a predicate |
| Timestamps | past-tense verb + `_at` | `resolved_at`, `last_seen_at` |
| Event columns | `dateTime`, never `timestamp` | MariaDB, which Atlas ran on until 2026-10-22, rewrote a `timestamp` column behind your back. See [the trap below](#datetime-not-timestamp) |
| Soft deletes | On `users`, `lab_pcs`, `network_devices` | Retiring a machine must not erase its history |
| Timestamps block | `$table->timestamps()` on every table except `telemetry_readings` | Telemetry has its own `recorded_at`; two extra datetimes × millions of rows is waste |

### Required unique keys

`users.email` · `users.tp_number` · `lab_pcs.asset_tag` · `network_devices.code` · `network_devices.mac_address` · `tickets.ticket_number` · `locations.code`

> Duplicate asset tags are the classic inventory-system demo failure. Enforce it at the database level, not in a validation rule alone.

### Required indexes

| Table | Index | Why |
|---|---|---|
| `telemetry_readings` | `(network_device_id, recorded_at)` | Every chart query. This is the only table that reaches six figures. |
| `telemetry_hourly` | Made by TimescaleDB on the aggregate's own storage | It is a continuous aggregate, not a table we index |
| `tickets` | `(status, priority)` | Queue filtering |
| `tickets` | `(assigned_to)` | "My tickets" |
| `tickets` | `(ticketable_type, ticketable_id)` | Tickets for one asset |
| `inspections` | `(lab_pc_id, completed_at)` | Inspection history, risk scoring input |
| `inspections` | `(user_id, completed_at)` | "What am I part-way through?" — the assistant's resume path |
| `inspection_results` | `(inspection_id, checklist_item_id)` unique | **One answer per item per inspection.** A phone on bad campus Wi-Fi double-submits; without this every answer lands twice and the derived outcome counts the same issue repeatedly. |
| `checklist_items` | `(checklist_template_id, sort_order)` | The inspection form reads exactly this: one template's items, in order |
| `shifts` | `(user_id, shift_date)` | "My shift today" |
| `alarms` | `(user_id, read_at)` | Unread badge count |
| `risk_assessments` | `(assessable_type, assessable_id, calculated_at)` | Risk history chart |

---

## The engine

PostgreSQL 16 with the TimescaleDB 2.31 extension, the `db` service in `compose.yaml`. Atlas ran on MariaDB 10.4 until 2026-10-22, and the move changed no table. Five things differ from MariaDB, and each has already mattered:

| | On PostgreSQL | Why it matters |
|---|---|---|
| Text comparison | Case-sensitive. Email addresses are stored in lower case and looked up the same way, through `User::normalizeEmail()`. List searches use `whereLike()`, which ignores case on every engine | MariaDB ignored case. Here an address typed with a capital became a second account, which its owner couldn't sign in to |
| Date buckets | The hourly and ten-minute telemetry buckets have one SQL expression per engine, chosen by driver | PostgreSQL can't cut a timestamp as text, the way MariaDB's `DATE_FORMAT` did |
| `json` columns | No `LIKE` or `REPLACE` on them. Cast to text first, as `atlas:renumber-tickets` does | PostgreSQL refuses string functions on `json` |
| TimescaleDB | Installed in `atlas` and `atlas_test` by `docker/postgres/init/01-databases.sql`, with usage reports off. Nothing uses it until Phase 1 makes readings a hypertable | A run on plain PostgreSQL would pass and hide the missing extension, so CI checks for it |
| Tests | `php artisan test` uses SQLite in memory. `phpunit.pgsql.xml` runs the suite on `atlas_test`, and CI runs both | SQLite forgives things PostgreSQL doesn't. See [07-testing.md](07-testing.md) |

---

## ERD — Cluster A: identity, places, assets

```mermaid
erDiagram
    USERS ||--o{ AUDIT_LOGS : records
    LOCATIONS ||--o{ LAB_PCS : houses
    LOCATIONS ||--o{ NETWORK_DEVICES : houses
    NETWORK_DEVICES ||--o{ NETWORK_DEVICES : "parent of"

    USERS {
        bigint id PK
        string tp_number UK
        string name
        string email UK
        string password
        string role "admin|engineer|assistant"
        string phone
        boolean is_active
        boolean must_change_password
        boolean is_demo
        timestamp deleted_at
    }
    LOCATIONS {
        bigint id PK
        string code UK
        string name
        string type "library|it_lab|cafeteria|other"
        string floor
        text description
    }
    LAB_PCS {
        bigint id PK
        string asset_tag UK
        bigint location_id FK
        string hostname
        string cpu
        smallint ram_gb
        string storage
        string os_version
        date purchase_date
        date warranty_expiry
        string status "operational|degraded|faulty|retired"
        decimal risk_score
        string risk_band
        timestamp last_inspected_at
        boolean is_demo
        timestamp deleted_at
    }
    NETWORK_DEVICES {
        bigint id PK
        string kind "access_point|switch|router|server|other"
        string code UK
        string name "nullable"
        bigint location_id FK
        bigint parent_id FK "nullable, another network device"
        bigint agent_id FK "nullable, the agent that watches it"
        datetime last_reading_at "newest reading, from any agent"
        string model
        string mac_address UK "nullable"
        string ip_address
        smallint client_capacity "access points only"
        date installed_at
        string status "online|degraded|offline|unknown|maintenance"
        string simulation_scenario "nullable: congestion|outage|feed_interruption"
        datetime simulation_started_at "nullable"
        decimal risk_score
        string risk_band
        timestamp last_seen_at
        boolean is_demo
        timestamp deleted_at
    }
    AUDIT_LOGS {
        bigint id PK
        bigint user_id FK "nullable"
        string action
        string auditable_type
        bigint auditable_id
        text description
        json changes "what the fields became"
        json previous "what they had been, nullable"
        string ip_address
        timestamp created_at
    }
```

## ERD — Cluster B: daily operations

```mermaid
erDiagram
    CHECKLIST_TEMPLATES ||--o{ CHECKLIST_ITEMS : contains
    CHECKLIST_TEMPLATES ||--o{ INSPECTIONS : "completed against"
    LAB_PCS ||--o{ INSPECTIONS : "inspected in"
    USERS ||--o{ INSPECTIONS : performs
    INSPECTIONS ||--o{ INSPECTION_RESULTS : yields
    CHECKLIST_ITEMS ||--o{ INSPECTION_RESULTS : answers
    INSPECTIONS ||--o{ TICKETS : "may raise"
    USERS ||--o{ TICKETS : reports
    TICKETS ||--o{ TICKET_COMMENTS : has
    SHIFT_TEMPLATES ||--o{ SHIFTS : generates
    USERS ||--o{ SHIFTS : "assigned to"
    SHIFTS ||--o{ INSPECTIONS : "carried out during"
    SHIFTS ||--o{ ALARMS : triggers

    CHECKLIST_TEMPLATES {
        bigint id PK
        string name
        text description
        int version
        boolean is_active
    }
    CHECKLIST_ITEMS {
        bigint id PK
        bigint checklist_template_id FK
        string label
        string category "boot|display|peripheral|network"
        boolean is_critical
        int sort_order
    }
    INSPECTIONS {
        bigint id PK
        bigint lab_pc_id FK
        bigint user_id FK
        bigint shift_id FK "nullable"
        bigint checklist_template_id FK
        string overall_result "pass|pass_with_issues|fail"
        text notes
        timestamp started_at
        timestamp completed_at
    }
    INSPECTION_RESULTS {
        bigint id PK
        bigint inspection_id FK
        bigint checklist_item_id FK
        string result "ok|issue|na"
        text remark
    }
    TICKETS {
        bigint id PK
        string ticket_number UK
        string title
        text description
        string category "hardware|network|peripheral|software|other"
        string priority "low|medium|critical"
        string status "open|acknowledged|in_progress|resolved|closed|reopened"
        string ticketable_type "LabPc or AccessPoint, nullable"
        bigint ticketable_id
        bigint location_id FK "nullable"
        bigint inspection_id FK "nullable"
        bigint inspection_result_id FK "nullable, the failed answer it came from"
        bigint reported_by FK
        bigint assigned_to FK "nullable"
        timestamp opened_at
        timestamp due_at
        timestamp acknowledged_at
        timestamp resolved_at
        timestamp closed_at
        text resolution_notes
        boolean is_demo
    }
    TICKET_COMMENTS {
        bigint id PK
        bigint ticket_id FK
        bigint user_id FK
        text body
        boolean is_internal
        boolean is_system "written by TicketService"
    }
    SHIFT_TEMPLATES {
        bigint id PK
        string name
        tinyint day_of_week "0-6"
        time start_time
        time end_time
        bigint location_id FK
        tinyint required_assistants
        boolean is_active
    }
    SHIFTS {
        bigint id PK
        bigint shift_template_id FK "nullable"
        bigint user_id FK
        bigint location_id FK
        date shift_date
        time start_time
        time end_time
        string status "scheduled|checked_in|completed|missed"
        timestamp checked_in_at
        timestamp checked_out_at
        text handover_notes
    }
    ALARMS {
        bigint id PK
        bigint user_id FK
        bigint shift_id FK "nullable"
        string type "shift_start|inspection_round|handover|shift_removed|ticket_raised|ticket_assigned|ap_degraded|ap_offline|agent_silent|sla_breach"
        string title
        text message
        string related_type "nullable"
        bigint related_id "nullable"
        timestamp trigger_at
        timestamp sent_at
        timestamp read_at
    }
```

## ERD — Cluster C: telemetry and analytics

```mermaid
erDiagram
    NETWORK_DEVICES ||--o{ TELEMETRY_READINGS : emits
    NETWORK_DEVICES ||--o{ TELEMETRY_HOURLY : "rolled up into"
    NETWORK_DEVICES ||--o{ RISK_ASSESSMENTS : "scored, access points only"
    LAB_PCS ||--o{ RISK_ASSESSMENTS : scored

    TELEMETRY_READINGS {
        bigint network_device_id FK
        bigint agent_id FK "nullable"
        bigint sequence "nullable, unique per agent and time"
        datetime received_at "nullable"
        datetime recorded_at "hypertable time column"
        smallint latency_ms
        smallint client_count
        decimal packet_loss_pct
        decimal channel_util_pct
        decimal throughput_mbps
        string status "online|degraded|offline"
        string source "simulated|live"
    }
    TELEMETRY_HOURLY {
        bigint network_device_id "continuous aggregate of TELEMETRY_READINGS"
        timestamp hour_bucket
        decimal avg_latency_ms
        smallint peak_client_count
        decimal avg_packet_loss_pct
        decimal avg_channel_util_pct
        decimal uptime_pct
        int reading_count
        string source
    }
    RISK_ASSESSMENTS {
        bigint id PK
        string assessable_type "LabPc or AccessPoint"
        bigint assessable_id
        decimal score "0-100"
        string band "low|medium|high|critical"
        json factors
        timestamp calculated_at
    }
```

> These three clusters are for working comprehension. The consolidated ERD is Figure 3 in [12-system-diagrams.md](12-system-diagrams.md).

---

## Table notes

### `agents` hold a token hash, never a token

Added 2026-10-28 (`2026_10_28_100001`, ADR-0017). A probe agent proves who it is with a token, `atl_` and 40 random characters. Only its SHA-256 hash is stored, unique, and `$hidden` on the model, so it never reaches JSON or the audit log; `token_hint` is its last four characters. The token is shown once, in the response to the request that made it, and never put in the session. `last_seen_at`, `last_address`, `version` and `clock_offset_ms` are written by the ingest endpoint on each authenticated request, quietly, because the readings are their history.

Retiring is a soft delete: the token stops working, its devices are released (`network_devices.agent_id` set to null), and its readings keep pointing at it. A retired agent's name isn't reused. The simulator's virtual agent (task 1.5) has no token at all.

`silent_since` (2026-10-29, `2026_10_29_090001`, task 1.6) is when Atlas noticed that the agent stopped reporting. Whether it is silent follows from `last_seen_at` and the clock; this column records that the silence was acted on, so one silence raises one alert. The collector sets it with a conditional update, so two rounds at once can't both claim it, and the agent's next request clears it. While an agent is silent or has never connected, its Online and Degraded devices have the status `unknown`.

`network_devices.agent_id` is the one agent that may report a device's readings.

### `network_devices` holds every kind of device

Their readings and hourly rows point at them by `network_device_id`, renamed from `access_point_id` on 2026-10-28 (`2026_10_28_090001`), because a switch's reading is not an access point's.

Added 2026-10-27 (`2026_10_27_090001`, ADR-0016). Until then this was `access_points`. The table was renamed rather than copied, so every id stayed, and the readings, hourly rows, tickets, risk assessments, alarms and audit rows that point at those ids kept working unchanged. `ap_code` became `code`.

`kind` says what a row is, and has no default: an insert that doesn't say fails rather than quietly becoming an access point. The `AccessPoint` model sees only rows of kind `access_point`, through a global scope, and sets that kind on every row it creates. `NetworkDevice` sees every kind, and loads an `access_point` row as an `AccessPoint`, so one found as a ticket's subject or a switch's child still has its capacity and risk score. That scope filters by kind, never by demo flag, so the rule against hiding demo rows is untouched.

Only access points use `client_capacity`, `risk_score`, `risk_band` and `simulation_scenario`, so those are null on other kinds. `mac_address` is nullable because a device watched by ping may have none on record. It stays unique, and both engines allow any number of nulls under a unique index.

`parent_id` is the device this one hangs from, such as the switch an access point plugs into. Phase 2 groups failures by it, so a loop must never be saved: the network devices form refuses a parent that is the device itself or hangs below it (`NetworkDevice::isSelfOrAncestorOf()`), and doesn't offer one. A parent must be a switch, router, server or other device still in the inventory, never an access point. Deleting a parent for good sets its children's `parent_id` to null. Retiring one is soft, and the form refuses it while anything is still connected to it.

On PostgreSQL the migration also renames the primary key, the location foreign key and the id sequence, which keep their old names through a table rename.


### `tickets` is polymorphic

A ticket can be about a lab PC or an access point. Rather than two ticket tables or two nullable foreign keys, `ticketable_type` + `ticketable_id` handles both.

**Built nullable in M4.** A fault can belong to a place rather than a machine — "no network anywhere on Level 6" is a real ticket that points at nothing more specific than a location. `location_id` is therefore denormalised off the asset rather than joined through the morph, so the engineer's queue can filter by place without reaching into two different asset tables.

The form posts a short key (`lab_pc` / `access_point`), never a class name. Accepting a raw FQCN from a request would let a crafted POST attach a ticket to any model in the system.

### `tickets` lifecycle columns

`status` and every `*_at` in the lifecycle are absent from `Ticket::$fillable`. Only `TicketService` writes them, and it refuses any move the state machine does not allow — see [`../modules/M4-ticketing.md`](modules/M4-ticketing.md)
. `due_at` is stamped once at creation from `config('atlas.sla_hours')`, so changing a target affects new tickets and leaves existing ones on the promise they were given.

```php
// Ticket model
public function ticketable(): MorphTo { return $this->morphTo(); }

// LabPc and AccessPoint models
public function tickets(): MorphMany { return $this->morphMany(Ticket::class, 'ticketable'); }
```

This is the one place a polymorphic relationship genuinely earns its keep — it is easy to explain, and the alternative is worse.

### `dateTime`, not `timestamp`

Every column recording **when something happened** is `dateTime`. This is the single most expensive schema mistake this project has made, and it has been made twice.

The trap below is MariaDB's. PostgreSQL has nothing like it, and on PostgreSQL Laravel's `timestamp()` and `dateTime()` make the same column type. The rule stays, so the schema writes a time one way, and the history stays because it explains migrations that still exist.

MySQL and MariaDB give the first `timestamp` column in a table an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` unless `explicit_defaults_for_timestamp` is on, which it is not in XAMPP. The column is then silently reset to the current time by **any** `UPDATE` that does not name it. No error, no warning, and the wrong value looks perfectly plausible.

It cost three columns before anybody noticed:

| Column | What it destroyed |
|---|---|
| `tickets.opened_at` | The SLA baseline `due_at` was computed against. A ticket acknowledged an hour later looked as if it had just been raised. `00:52:02` became `01:04:58` from a status change alone |
| `telemetry_hourly.hour_bucket` | The aggregator upserts, so every re-run rewrote the bucket to `now()`, collapsing distinct hours onto one value and then colliding with the unique index |
| `telemetry_readings.recorded_at` | Nothing yet — inserted only, never updated. The same trap, armed and waiting for the first `UPDATE` anybody wrote |

Those three were converted on 2026-08-29. **Eight more were left**, and it is worth being precise about why they were not causing damage: the implicit auto-update only lands on a **NOT NULL** timestamp column, and all eight happened to be nullable. The protection was an accident of the null flag rather than a property of the design, and it would have disappeared the first time somebody decided one of them should be required. They were converted on 2026-09-08.

Two things follow for anyone adding a column:

- **`created_at` and `updated_at` stay as they are.** Being touched automatically is their entire job.
- **Run `php artisan atlas:check-schema` after any migration.** On MariaDB it read `information_schema` and failed on any column that had acquired `on update`. The test suite could not catch this, because it ran on SQLite, which has no such behaviour, and that is why the bug survived four weeks of a green suite. On PostgreSQL the command skips that audit and checks the data: rows whose own timeline can't have happened, and scores out of range.

### `risk_assessments` keeps history

A new row per asset per run, rather than overwriting. Costs a few thousand rows and buys the ability to chart an asset's risk trending upward over time.

The denormalised `risk_score` and `risk_band` columns on `lab_pcs` and `network_devices` (access points only) are a deliberate cache of the latest assessment, so list views do not need a subquery. Update them in the same transaction that writes the assessment.

### `checklist_templates.version`

An inspection stores `checklist_template_id`, so it is permanently tied to the template version it was completed against. Editing an active template creates a new version rather than mutating the existing one — otherwise historical inspections reference items that no longer exist.

**Built in M3.** `ChecklistService` owns the copy-on-write; `is_active` moves to the new version so an edit never leaves the system without a checklist. `is_active` is a plain boolean with a plain index, *not* a unique constraint: "only one active" is a rule the service enforces, because a unique index on the column would forbid a second **inactive** template, which is precisely what versioning produces. MariaDB has no partial unique index to express it properly.

### `inspections.shift_id` has no foreign key yet

The column exists because the ERD has it, but `shifts` is M6's table and does not exist. M6's migration adds the constraint:

```php
$table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
```

Nothing writes the column until then. This is the pattern to follow whenever a module needs a column pointing at a table nobody has built yet — add the column, not the other module's table.

### `telemetry_readings.source`

`simulated` or `live`. Both can sit in the same table. This is what makes the mock/real strategy work without a second database — see [05-data-strategy.md](05-data-strategy.md).

**Built in M5.** `telemetry_readings` is the one table exempt from the `$table->timestamps()` convention: `recorded_at` is the only time that means anything, and two extra datetimes across ~1.7 million rows a month is real cost for nothing.

**From agents since 2026-10-28** (`2026_10_28_110001`, ADR-0017). A reading from a probe agent carries `agent_id` and its `sequence`, and the unique key on agent, sequence and `recorded_at` stores a retried batch once; it includes `recorded_at` because every unique key on a hypertable must. `recorded_at` is when the agent measured it, and `received_at` when Atlas got it. The simulator's readings carry its virtual agent and a sequence too (task 1.5). Rows written before agents have neither, and nulls never collide. On SQLite `agent_id` has no foreign key, because adding one rebuilds the table and the rebuild fails on the `telemetry_hourly` view. `network_devices.last_reading_at` is the newest reading's time: only a newer reading moves a device's status, so a late one fills in history without turning a recovered device back to offline.

**A TimescaleDB hypertable since 2026-10-28** (`2026_10_28_090002`, ADR-0011). Rows are stored in chunks of `retention.chunk_days` by `recorded_at`, and a chunk is compressed once it is `retention.compress_after_days` old, segmented by device and ordered newest first. So the table has **no `id`**: a unique key on a hypertable must include the time column, and no code looks a reading up by id. The newest reading per device is found by time, with a LATERAL lookup that stops at the newest chunk (`TelemetryService::latestReadings()`). On SQLite, which runs the quick local suite, it stays a plain table. The migration rebuilt the table and copied every row across, because SQLite can't drop a primary key column.

`status` here is a plain string and must **not** be cast to `NetworkStatus`. A reading records a MEASURED state — online, degraded or offline only. `NetworkStatus` is wider because it carries `maintenance`, which is an administrative decision about the access point rather than something anyone measured.

### `network_devices.simulation_scenario`

Added 2026-09-19 (`2026_09_19_090001`) for the Network monitor's simulation controls (FR-314). A string cast to `SimulationScenario` — `congestion`, `outage` or `feed_interruption` — and NULL for normal behaviour. `simulation_started_at` is `dateTime`, per the trap below.

**It is not a status.** Only `SimulatedReadings` reads it, and only for moments at or after `simulation_started_at`, so the backfill never rewrites history as congested. `status` still comes from the classifier. On a device a real agent watches the column is ignored, and the controls that set it are refused. Both columns are written only by `SimulationService` with `forceFill()`, never from request input, and every change goes through the audit observer with the user who made it.

### `telemetry_hourly` is derived from the readings

Since 2026-10-28 (`2026_10_28_090003`, ADR-0011) this is a TimescaleDB **continuous aggregate**, not a table our code writes. It was a table filled by an hourly command, and it drifted: the demo seeder wrote hours no reading backed, and a wide re-run once threatened to overwrite a month of charts with different numbers. Now the database derives every hour from `telemetry_readings`, so the two can't disagree. The `TelemetryHourly` model refuses to save. On SQLite it is a plain view over the readings.

It has the same columns as the table had, grouped by device, hour and `source`. Latency **excludes offline readings**: an unreachable device has no latency, and counting it as zero would make an outage read as its best hour. Clients are the hour's **peak**, and uptime is the share of readings that weren't offline, which is the figure the report quotes.

How it stays current, all from `config('atlas.retention')` and set by `atlas:telemetry-storage` every time the app starts:

| Job | What it does |
|---|---|
| Refresh | Every `hourly_refresh_minutes`, re-stores the hours over the last `late_reading_days` that have new readings, stopping an hour short of now |
| Live hours | Hours newer than the last stored one are read straight from the readings (`materialized_only = false`), so the newest hour is never stale |
| Retention | Raw readings dropped after `raw_days`, hourly summaries after `aggregate_months` |
| Compression | Raw chunks compressed after `compress_after_days` |

**Writing old readings needs a refresh.** The aggregate reads live only the hours after the last one it stored. Readings written further back, by the seeder, a backfill or a purge, stay invisible until those hours are refreshed. So each of those calls `TelemetryStorage::refreshHourly()` afterwards. TimescaleDB can't refresh inside a transaction, so inside one the call does nothing, and tests rely on that: no test DB ever stores an hour, so the aggregate reads everything live.

On a database migrated from the old table, the hourly rows that no readings backed are gone. Re-seeding the demo data writes a fortnight of readings and brings them back.

### `audit_logs` stores both sides of a change

`changes` is what the fields became, `previous` is what they had been, and the two always describe the same field names.

The observer originally wrote only `changes`, from `getChanges()`. That answers "who changed this" and cannot answer "from what", which is the question an audit trail actually gets asked — "role changed to admin" is not evidence of anything without knowing it had been assistant. `previous` was added on 2026-09-04.

**`previous` is nullable and rows written before that date have none.** There is no honest way to backfill a value nobody recorded, so those rows render as the new value alone rather than claiming a change from nothing.

**Both halves are filtered by the same exclusion list.** Building `previous` from the whole original model instead of from the already-filtered `changes` would write the old password hash into a table administrators can read.

**That list is the model's own `$hidden`, not a list kept in the observer.** It used to be a hand-written `['updated_at', 'remember_token', 'password']`, and it missed `users.calendar_token` — the key that reads somebody's roster feed without signing in. Regenerating a calendar link put a working token into `audit_logs` in plain text. Keying off `$hidden` means the next secret column is covered by the act of declaring it secret. `updated_at` stays excluded separately: it is not a secret, just noise that would make every row look like something happened.

**System-written caches are left out too, through the model's `AUDIT_EXCEPT`.** `network_devices.last_seen_at` changes on every collection round, so auditing it wrote one row per access point per minute, about 42,000 a day, and buried every human action on the audit screen. `AccessPoint` excludes `last_seen_at` and `risk_score`; `LabPc` excludes `last_inspected_at` and `risk_score`. Their history is already kept in `telemetry_readings`, `inspections` and `risk_assessments`. A risk band change is still audited, and a save that changes nothing else writes no row. Rows written before 2026-09-23 may hold these heartbeats; they were left in place.

**The collector also saves an access point's `status` quietly** (`saveQuietly()`), because every reading row already records it and at peak an access point near a threshold flaps every few minutes: auditing each flap wrote hundreds of rows an hour, credited to whoever pressed "Generate sample". A status an administrator sets, such as maintenance, goes through a normal save and is audited.

**Shifts are audited** (added 2026-09-23): who was given one, who took it over, check-in and out, missed marking, and removal. A shift row is described by its label, "Thu 24 Sep, 09:00–13:00", and `user_id` is shown as the assistant's name. A moment in a change, such as a check-in, is stored as written (UTC) and shown on the campus clock, using the subject model's casts.

### `is_demo`

On `users`, `lab_pcs`, `network_devices`, `tickets`. Set **only** by `DemoDataSeeder`, and read by `php artisan atlas:purge-demo-data`.

Do **not** add a global Eloquent scope that hides demo rows. It will silently hide data during a demo and cost someone an afternoon.
