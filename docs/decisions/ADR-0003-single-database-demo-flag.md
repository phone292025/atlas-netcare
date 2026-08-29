# ADR-0003: One database; demo data is tagged, not separated

**Status:** Accepted
**Date:** 2026-08-26
**Deciders:** Group Atlas

## Context

We need rich data for development, testing and the demo, and we may need to hand over a system containing only real records. The obvious instinct is to keep the two apart.

Most of our data is not really "mock" at all. Users, locations, lab PCs, checklists, inspections, tickets and shifts are entered by people through our own interface — a seeder just saves the typing. Only telemetry comes from outside (ADR-0002).

## Decision

One MySQL database, one schema, both kinds of data in the same tables.

- `telemetry_readings.source` and `telemetry_hourly.source` hold `simulated` or `live`.
- `is_demo` boolean on `users`, `lab_pcs`, `access_points`, `tickets`, set **only** by `DemoDataSeeder`.
- Seeders split in two: `ReferenceSeeder` always runs and contains the minimum a real deployment needs; `DemoDataSeeder` runs only when `config('atlas.demo_mode')` is true.
- `php artisan atlas:purge-demo-data` removes every tagged row and every simulated reading, leaving real data untouched. **⚠️ This command does not exist yet** — the decision and the flags it depends on stand, but the tool that acts on them has not been written. See `docs/07-data-strategy.md`.

No global Eloquent scope hides demo rows.

## Consequences

**Easier**
- One schema to migrate, one set of foreign keys, no drift between environments.
- `php artisan migrate:fresh --seed` reproduces the full demo dataset identically on any machine.
- Removing demo data is one command, which is a direct answer to an obvious viva question.
- Feature tests reuse the same factories as the demo seeder, so there is one set of fixtures.

**Harder**
- Four tables carry an `is_demo` column a production system would not need. We accept this; it is four booleans.
- Anyone writing a report query must decide consciously whether to include demo rows.

## Alternatives considered

**A second database for mock data.** Rejected: doubles migrations, breaks foreign keys across the boundary, and guarantees the two schemas drift apart within three weeks.

**A global scope excluding demo rows.** Rejected: it will silently hide data during a demo. Explicit filtering on the few screens that need it is safer and more readable.

**Separate `_demo` tables.** Rejected: every query would need to know which table to hit, which is the coupling we are trying to avoid.
