# Modules

> **Last updated:** 2026-08-30

Seven modules, one owner each. The split exists because Section F of the guidelines requires **individual** DFD Level 1 diagrams, data dictionaries and flowcharts — so each member documents what they personally built.

## Ownership map

| ID | Module | Track | Owner | Depends on | Sprint | Status |
|---|---|---|---|---|---|---|
| [M1](M1-identity-and-audit.md) | Identity, roles and audit log | A | *unassigned* | — | 1 | ✅ built |
| [M2](M2-locations-and-assets.md) | Locations and asset inventory | B | *unassigned* | M1 | 1 | ✅ built |
| [M3](M3-checklists-and-inspections.md) | Checklists and inspections | A | *unassigned* | M1, M2 | 2 | ✅ built |
| [M4](M4-ticketing.md) | Workspace ticketing | A | *unassigned* | M1, M2, M3 | 3 | ✅ built |
| [M5](M5-telemetry-and-dashboard.md) | Telemetry and AP dashboard | B | *unassigned* | M2 | 2–3 | ✅ built |
| [M6](M6-shifts-and-alarms.md) | Shifts, roster and alarms | A | *unassigned* | M1 | 3 | ✅ built |
| [M7](M7-predictive-and-reports.md) | Predictive risk and reports | B | *unassigned* | M3, M4, M5 | 4 | ✅ built 2026-08-30 |

**All seven modules are coded.** What is outstanding is not code: every module still owes a DFD 1, a data dictionary and a flowchart for the report, and **nobody owns any module.** The diagrams are worth 15 marks between them.

**Assign owners at the next team meeting**, then mirror into [../02-team-and-ownership.md](../../coursework/02-team-and-ownership.md), `README.md` and the Workload Matrix.

## Working in a module

- Branch from `main` as `m4-ticketing/short-description`.
- Do not edit files owned by another module. Found a bug there? Tell the owner.
- Shared files — `config/atlas.php`, layouts, base components, `routes/web.php` — change by agreement, because a conflict there blocks everyone.
- Update your module spec as you build. It becomes your report chapter.

## Definition of done

A module is not done until all seven are true:

1. Migrations written, and [../04-database.md](../04-database.md) updated in the same commit
2. Model with relationships, enum casts and any scopes
3. Service holding the business logic, with unit tests for anything non-trivial
4. Controllers thin, Form Requests validating, Policy authorising
5. Screens built from shared components, with a working empty state
6. Feature test: one happy path, one authorisation failure
7. Module spec updated with the DFD 1, data dictionary and flowchart for the report
