# Modules

> **Last updated:** 2026-09-26

Seven modules, each a slice of the system with its own spec.

## Module map

| ID | Module | Track | Depends on | Sprint | Status |
|---|---|---|---|---|---|
| [M1](M1-identity-and-audit.md) | Identity, roles and audit log | A | — | 1 | ✅ built |
| [M2](M2-locations-and-assets.md) | Locations and asset inventory | B | M1 | 1 | ✅ built |
| [M3](M3-checklists-and-inspections.md) | Checklists and inspections | A | M1, M2 | 2 | ✅ built |
| [M4](M4-ticketing.md) | Workspace ticketing | A | M1, M2, M3 | 3 | ✅ built |
| [M5](M5-telemetry-and-dashboard.md) | Telemetry and AP dashboard | B | M2 | 2–3 | ✅ built |
| [M6](M6-shifts-and-alarms.md) | Shifts, roster and alarms | A | M1 | 3 | ✅ built |
| [M7](M7-predictive-and-reports.md) | Predictive risk and reports | B | M3, M4, M5 | 4 | ✅ built 2026-08-30 |

## Working in a module

- Branch from `main` as `m4-ticketing/short-description`.
- Do not edit another module's files without telling whoever is working on it.
- Shared files — `config/atlas.php`, layouts, base components, `routes/web.php` — change by agreement, because a conflict there blocks everyone.
- Update the module spec as you build.

## Definition of done

A module is not done until all seven are true:

1. Migrations written, and [../04-database.md](../04-database.md) updated in the same commit
2. Model with relationships, enum casts and any scopes
3. Service holding the business logic, with unit tests for anything non-trivial
4. Controllers thin, Form Requests validating, Policy authorising
5. Screens built from shared components, with a working empty state
6. Feature test: one happy path, one authorisation failure
7. Module spec updated
