# M2 — Locations and Asset Inventory

**Track:** B · **Owner:** *unassigned* · **Sprint:** 1 · **Depends on:** M1 · **Blocks:** M3, M5

> The lightest module. Whoever owns it should expect to help M5 from around week 9.

## Purpose

Maintain the reference data every other module points at: campus locations, lab PC hardware records, and access point records.

## Requirements covered

FR-403, FR-404, FR-405 · NFR-08

## Tables owned

`locations` · `lab_pcs` · `access_points` — see [../06-database.md](../06-database.md)

## Components

| Kind | Items |
|---|---|
| Models | `Location`, `LabPc`, `AccessPoint` |
| Enums | `DeviceStatus`, `ApStatus` |
| Controllers | `Admin\LocationController`, `Admin\LabPcController`, `Admin\AccessPointController` |
| Services | — (standard CRUD) |
| Policies | admin-only, enforced by middleware |
| Seeders | Locations go in `ReferenceSeeder`; PCs and APs in `DemoDataSeeder` |

## Screens

Admin: locations index/create/edit · lab PC inventory index/create/edit/show · access point inventory index/create/edit/show

The lab PC and AP **show** pages are important — they are where M5, M4 and M7 surface their data (telemetry chart, ticket history, risk factors). Build them with slots the other modules can fill.

## Key decisions

- **Soft deletes, not hard.** Retiring a machine must not erase its inspection and ticket history.
- **`asset_tag` and `mac_address` are unique at the database level**, not just in validation. Duplicate asset tags are the classic inventory demo failure.
- **`risk_score` and `risk_band` are denormalised caches** of the latest `risk_assessments` row, so list views need no subquery. M7 updates them.
- **Locations are reference data**, seeded in `ReferenceSeeder`, never tagged `is_demo`.

## Definition of done

- [ ] Admin can create, edit and retire a location, lab PC and access point
- [ ] Duplicate asset tag or MAC address is rejected with a clear message
- [ ] Retiring a PC hides it from active lists but keeps its history reachable
- [ ] Show pages expose slots for telemetry, tickets and risk
- [ ] Locations seeded in `ReferenceSeeder`; assets seeded in `DemoDataSeeder` with `is_demo = true`
- [ ] Feature tests: create, duplicate rejection, retire, non-admin denied
- [ ] DFD 1, data dictionary and flowchart drafted
