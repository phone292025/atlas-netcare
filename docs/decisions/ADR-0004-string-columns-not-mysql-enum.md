# ADR-0004: Use string columns cast to PHP backed enums, not MySQL ENUM

**Status:** Accepted
**Date:** 2026-08-26
**Deciders:** Group Atlas

## Context

Eleven schema fields get a PHP enum: user role, ticket status, ticket priority, ticket category, device status, AP status, checklist result, inspection result, shift status, alarm type, and risk band.

Three further fields are enumerated in the schema but deliberately get **no** enum class, because nothing in the application branches on their value — they are labels for filtering and reporting only:

| Field | Values | Why no enum |
|---|---|---|
| `locations.type` | `library` · `it_lab` · `cafeteria` · `other` | Only ever displayed and filtered |
| `checklist_items.category` | `boot` · `display` · `peripheral` · `network` | Grouping label on the inspection form |
| `telemetry_readings.source` | `simulated` · `live` | Written by the collector, read by reports |

> ⚠️ `telemetry_readings.source` (`simulated`/`live`) says *what kind of data* a reading is. Until 2026-10-28 it was easy to confuse with `config('atlas.telemetry_driver')` (`simulated`/`snmp`), which said *how* it was collected. That setting is gone (ADR-0010): the source now follows the agent that sent the reading, `simulated` for the simulator's virtual agent and `live` for a probe agent.

MySQL offers a native `ENUM` column type. Laravel offers PHP 8.1 backed enums with model casting.

The ticket lifecycle is still an open question (Q3). Statuses are likely to change during development.

## Decision

Every enumerated field is a `string` column in MySQL, backed by a PHP enum class in `app/Enums/`, wired through the model `$casts` array.

```php
protected function casts(): array
{
    return ['status' => TicketStatus::class, 'priority' => TicketPriority::class];
}
```

Validation uses `Rule::enum(TicketStatus::class)`. UI labels and colours come from methods on the enum, so there is one source of truth per concept.

## Consequences

**Easier**
- Adding a status is a one-line code change, not an `ALTER TABLE` on a live table.
- The set of valid values is visible in the codebase, not hidden in the schema.
- Type safety: `TicketStatus::InProgress` is checked by the IDE; `'in_progres'` is a silent bug.
- Labels, colours and permitted transitions live on the enum, so badges and forms cannot drift apart.

**Harder**
- The database will accept an invalid string if something bypasses Eloquent. Nothing in our system does, but a raw SQL fix in an emergency could.
- Slightly more storage than a native `ENUM`. Irrelevant at our scale.

## Alternatives considered

**MySQL `ENUM` columns.** Rejected: changing the value set requires a table alteration, which is exactly the operation we do not want to be running in week 10 with six teammates holding their own local databases.

**Lookup tables with foreign keys.** Rejected: correct for data that users manage, wrong for values the code branches on. Nobody will ever add a ticket priority through the UI.

**Plain string constants.** Rejected: no type safety, and no natural home for labels and colours.
