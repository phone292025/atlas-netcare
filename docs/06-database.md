# Database Design

> **Last updated:** 2026-08-28 · **Status:** design freeze 15 Sep 2026 — after that, changes require an ADR
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
| Enumerations | MySQL `string` column + PHP backed enum + `$casts` | Adding a status later is a code change, not a risky `ALTER TABLE`. [ADR-0004](decisions/ADR-0004-string-columns-not-mysql-enum.md) |
| Percentages | `decimal(5,2)` | Exact. Never `float` for a number a user reads |
| Money | *(none in this system)* | — |
| Booleans | `is_` or `has_` prefix | Reads as a predicate |
| Timestamps | past-tense verb + `_at` | `resolved_at`, `last_seen_at` |
| Soft deletes | On `users`, `lab_pcs`, `access_points` | Retiring a machine must not erase its history |
| Timestamps block | `$table->timestamps()` on every table except `telemetry_readings` | Telemetry has its own `recorded_at`; two extra datetimes × millions of rows is waste |

### Required unique keys

`users.email` · `users.tp_number` · `lab_pcs.asset_tag` · `access_points.ap_code` · `access_points.mac_address` · `tickets.ticket_number` · `locations.code`

> Duplicate asset tags are the classic inventory-system demo failure. Enforce it at the database level, not in a validation rule alone.

### Required indexes

| Table | Index | Why |
|---|---|---|
| `telemetry_readings` | `(access_point_id, recorded_at)` | Every chart query. This is the only table that reaches six figures. |
| `telemetry_hourly` | `(access_point_id, hour_bucket)` unique | Prevents duplicate aggregation |
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

## ERD — Cluster A: identity, places, assets

```mermaid
erDiagram
    USERS ||--o{ AUDIT_LOGS : records
    LOCATIONS ||--o{ LAB_PCS : houses
    LOCATIONS ||--o{ ACCESS_POINTS : houses

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
    ACCESS_POINTS {
        bigint id PK
        string ap_code UK
        bigint location_id FK
        string model
        string mac_address UK
        string ip_address
        smallint client_capacity
        date installed_at
        string status "online|degraded|offline|maintenance"
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
        string ticketable_type "LabPc or AccessPoint"
        bigint ticketable_id
        bigint location_id FK "nullable"
        bigint inspection_id FK "nullable"
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
        string type "shift_start|inspection_round|handover|ap_offline|sla_breach"
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
    ACCESS_POINTS ||--o{ TELEMETRY_READINGS : emits
    ACCESS_POINTS ||--o{ TELEMETRY_HOURLY : "rolled up into"
    ACCESS_POINTS ||--o{ RISK_ASSESSMENTS : scored
    LAB_PCS ||--o{ RISK_ASSESSMENTS : scored

    TELEMETRY_READINGS {
        bigint id PK
        bigint access_point_id FK
        timestamp recorded_at
        smallint latency_ms
        smallint client_count
        decimal packet_loss_pct
        decimal channel_util_pct
        decimal throughput_mbps
        string status "online|degraded|offline"
        string source "simulated|live"
    }
    TELEMETRY_HOURLY {
        bigint id PK
        bigint access_point_id FK
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

> For the report you need **one consolidated ERD** (Section F item 13). These three clusters are for working comprehension — redraw them as a single diagram in draw.io or Lucidchart before submission.

---

## Table notes

### `tickets` is polymorphic

A ticket can be about a lab PC or an access point. Rather than two ticket tables or two nullable foreign keys, `ticketable_type` + `ticketable_id` handles both.

```php
// Ticket model
public function ticketable(): MorphTo { return $this->morphTo(); }

// LabPc and AccessPoint models
public function tickets(): MorphMany { return $this->morphMany(Ticket::class, 'ticketable'); }
```

This is the one place a polymorphic relationship genuinely earns its keep — it is easy to explain, and the alternative is worse.

### `risk_assessments` keeps history

A new row per asset per run, rather than overwriting. Costs a few thousand rows and buys the ability to chart an asset's risk trending upward over time — one of the strongest figures available for the report.

The denormalised `risk_score` and `risk_band` columns on `lab_pcs` and `access_points` are a deliberate cache of the latest assessment, so list views do not need a subquery. Update them in the same transaction that writes the assessment.

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

`simulated` or `live`. Both can sit in the same table. This is what makes the mock/real strategy work without a second database — see [07-data-strategy.md](07-data-strategy.md).

### `is_demo`

On `users`, `lab_pcs`, `access_points`, `tickets`. Set **only** by `DemoDataSeeder`. Enables `php artisan atlas:purge-demo-data`.

Do **not** add a global Eloquent scope that hides demo rows. It will silently hide data during a demo and cost someone an afternoon.

---

## Data dictionary ownership

Section F item 12 requires an **individual** data dictionary per member. Each owner documents the tables their module owns.

| Module | Tables |
|---|---|
| M1 | `users`, `audit_logs` |
| M2 | `locations`, `lab_pcs`, `access_points` |
| M3 | `checklist_templates`, `checklist_items`, `inspections`, `inspection_results` |
| M4 | `tickets`, `ticket_comments` |
| M5 | `telemetry_readings`, `telemetry_hourly` |
| M6 | `shift_templates`, `shifts`, `alarms` |
| M7 | `risk_assessments` |

Data dictionary format for each column: **name · type · size · constraints · description · example value**.
