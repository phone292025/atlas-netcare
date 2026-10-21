# Modules

> **Last updated:** 2026-10-22

Seven modules, each a slice of the system with its own spec.

## Module map

| ID | Module | Depends on | Status |
|---|---|---|---|
| [M1](M1-identity-and-audit.md) | Identity, roles and audit log | none | Built |
| [M2](M2-locations-and-assets.md) | Locations and asset inventory | M1 | Built |
| [M3](M3-checklists-and-inspections.md) | Checklists and inspections | M1, M2 | Built |
| [M4](M4-ticketing.md) | Workspace ticketing | M1, M2, M3 | Built |
| [M5](M5-telemetry-and-dashboard.md) | Telemetry and AP dashboard | M2 | Built. Rebuilt in 2.0 Phase 1 |
| [M6](M6-shifts-and-alarms.md) | Shifts, roster and alarms | M1 | Built |
| [M7](M7-predictive-and-reports.md) | Predictive risk and reports | M3, M4, M5 | Built |

## Working in a module

- Work on the branch [../roadmap.md](../roadmap.md) names, in small commits that each pass the checks.
- Update the module spec in the same change as the code it describes.

## Definition of done

A module is not done until all seven are true:

1. Migrations written, and [../04-database.md](../04-database.md) updated in the same commit
2. Model with relationships, enum casts and any scopes
3. Service holding the business logic, with unit tests for anything non-trivial
4. Controllers thin, Form Requests validating, Policy authorising
5. Screens built from shared components, with a working empty state
6. Feature test: one happy path, one authorisation failure
7. Module spec updated
