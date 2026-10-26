# ADR-0016: Keep every network device in one table, with access points as one kind

**Status:** Accepted
**Date:** 2026-10-27
**Deciders:** Phone Myat Ko

## Context

Atlas 1.0 watched one kind of device, the Wi-Fi access point, in the `access_points` table. Roadmap task 1.1 adds switches, routers and servers, each with an optional parent: an access point hangs from the switch it plugs into. Phase 2 needs that parent to open one incident for a switch instead of one per access point behind it ([ADR-0014](ADR-0014-incidents-and-domain-events.md)).

Every kind needs the same things to be watched: a status, when it was last heard from, readings, an agent that watches it, incidents and tickets. Only access points have clients, a capacity, a risk score and simulation scenarios.

About 90 places in the code read `ap_code`, and 50 files use the `AccessPoint` model. Tickets, risk assessments, alarms and audit rows point at access points by class name and id.

## Decision

- **One table, `network_devices`, for every kind.** The migration renames `access_points`, so every id stays and nothing that points at an access point changes. `ap_code` becomes `code`, shared by every kind and unique across them.
- **A `kind` column**, cast to `DeviceKind`: `access_point`, `switch`, `router`, `server`, `other`. It has no default, so a row that doesn't say what it is fails to insert.
- **`NetworkDevice` is the model for every kind. `AccessPoint` extends it**, sees only its own kind through a global scope, and sets that kind on every row it creates. A row of kind `access_point` loaded through `NetworkDevice` comes back as an `AccessPoint`.
- **`parent_id`** points at another network device. Deleting a parent for good leaves its children without one.
- **Access point columns stay on the table** and are null for other kinds: `client_capacity`, `risk_score`, `risk_band`, `simulation_scenario`.
- **The status enum is `NetworkStatus`.** `DeviceStatus` was already taken by lab PCs.

## Consequences

**Easier**
- Readings, agents, heartbeats, incidents and the parent grouping are written once and work for every kind.
- Existing access point code and screens keep working: they ask for `AccessPoint`, and only ever get access points.
- A new kind is a new enum case, not a new table.

**Harder**
- A query through `NetworkDevice` sees every kind, so code that means access points must ask for `AccessPoint`. Raw queries on the table must filter by `kind` themselves, as the reports do.
- Columns that only access points use are null for other kinds. The model and the forms keep them apart, not the database.
- Eloquent has no built-in single-table inheritance. `NetworkDevice::newFromBuilder()` picks the class, and a test pins it.

## Alternatives considered

**A second table for switches and routers, beside `access_points`.** Less change today. But status, last seen, readings, heartbeats, incidents and the parent link would each need two versions or a polymorphic key, and a parent link between two tables can't have a foreign key.

**A `network_devices` table with `access_points` as a one-to-one extension.** This is class-table inheritance: the shared columns move to the new table and the access point columns stay behind. It keeps nulls out of the schema, but every access point query becomes a join, status moves away from the model that 50 files read it from, and Eloquent supports this pattern no better than a single table.
