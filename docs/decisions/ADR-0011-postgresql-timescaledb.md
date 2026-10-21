# ADR-0011: Move to PostgreSQL with TimescaleDB

**Status:** Accepted
**Date:** 2026-10-22
**Deciders:** Phone Myat Ko

## Context

Atlas 1.0 runs on MariaDB 10.4 because that is what XAMPP ships. Four of its problems come from the engine rather than the code:

- **Rules that should live in the database can't.** "Only one open ticket per access point" needs a unique index over open rows only, a partial unique index. MariaDB has none, so the rule is a check in PHP, and two requests can both pass it.
- **Summaries drift from their source.** `telemetry_hourly` is a table that code writes. Seeded rows and rows aggregated from raw readings sit side by side, and a wide re-aggregation once threatened to overwrite a month of charts with different numbers.
- **Retention is a delete loop.** `atlas:prune-telemetry` deletes old readings in batches of 2,000, which grows with the data.
- **The `timestamp` trap.** MariaDB silently rewrites the first `timestamp` column of a table on every update. It destroyed `tickets.opened_at` once, and `atlas:check-schema` exists mainly to catch it.

ADR-0010 makes telemetry real. A few hundred devices, each measured every 30 seconds, is around a million readings a day. That is the data that grows fastest, and the data most affected by all four problems.

[ADR-0012](ADR-0012-docker-compose-stack.md) moves Atlas into Docker, so the engine is no longer limited to what XAMPP installs.

## Decision

Atlas runs on **PostgreSQL 16 with the TimescaleDB extension**.

- **Phase 0 moves the engine with the schema unchanged.** Every migration, query and test works on PostgreSQL, and nothing else changes yet. Code written for MariaDB (the hourly bucket expressions, the `timestamp` checks, `information_schema` queries) gets a PostgreSQL version.
- **Phase 1 uses TimescaleDB for telemetry.** Readings become a hypertable, which is stored in time chunks and compressed after a week. Hourly and per-minute summaries become continuous aggregates that the database maintains from the raw readings. Retention drops whole chunks.
- **"Only one" rules move into the database** as partial unique indexes as each module is touched. For example, one open incident per rule and device.
- **Text comparison stays case-insensitive where users expect it.** MariaDB compared text without regard to case, and PostgreSQL doesn't. Email addresses are stored in lower case and compared that way. Searches that relied on case-insensitive `LIKE` use `ILIKE`.
- **Existing data is reseeded, not migrated.** All of it is demo data. A final MariaDB dump is kept outside the repository.
- **Tests:** SQLite stays for quick local runs. CI runs the whole suite on PostgreSQL with TimescaleDB, and that run decides whether a change is green. Tests for TimescaleDB features run only there.

## Consequences

**Easier**
- Rules such as "one open incident per device" are enforced by the database, so two requests can't both get past them.
- Summaries can't drift, because the database derives them from the raw readings.
- Old data goes by dropping a chunk, in about the same time whatever its size.
- Compression keeps months of raw readings small enough for a modest server.
- `SERIALIZABLE` isolation detects write skew, for the rare rule that needs it.

**Harder**
- Every query written for MariaDB has to be checked. Date functions and case sensitivity differ.
- Atlas no longer runs on a plain XAMPP install. You need Docker, or PostgreSQL installed separately.
- The `timestamp` trap and its checks go away, but anything relying on MariaDB's case-insensitive collation has to be found and fixed.
- TimescaleDB's compression and continuous aggregates use the Timescale License. It's free for self-hosting Atlas, but it doesn't allow selling TimescaleDB itself as a hosted database service.

## Alternatives considered

**Stay on MariaDB, with monthly partitions on readings.** Partitions make retention cheap. But summaries would still be written by our own code, and "only one open" rules would need generated-column workarounds. It would leave three of the four problems in place.

**PostgreSQL without TimescaleDB.** Native partitioning covers retention, and materialized views can hold summaries, but they have to be refreshed in full. TimescaleDB refreshes only the hours that changed, which matters once readings arrive late from agents that were offline.

**A separate time-series database,** such as InfluxDB, for telemetry only. That means two databases to run, back up and keep in step, and joins between readings and devices across systems. One database with an extension does both.
