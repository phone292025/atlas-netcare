# M2 — Locations and Asset Inventory

**Track:** B · **Sprint:** 1 · **Depends on:** M1 · **Blocks:** M3, M5

> **Last updated:** 2026-09-26

> The lightest module. Whoever owns it should expect to help M5 from around week 9.

## Purpose

Maintain the reference data every other module points at: campus locations, lab PC hardware records, and access point records.

## Requirements covered

FR-403, FR-404, FR-405 · NFR-08

## Tables owned

`locations` · `lab_pcs` · `access_points` — see [../04-database.md](../04-database.md)

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

- [x] Admin can create, edit and retire a location, lab PC and access point
- [x] Duplicate asset tag or MAC address is rejected with a clear message
- [x] Retiring a PC hides it from active lists but keeps its history reachable
- [x] Show pages expose slots for telemetry, tickets and risk
- [x] Locations seeded in `ReferenceSeeder`; assets seeded in `DemoDataSeeder` with `is_demo = true`
- [x] Feature tests: create, duplicate rejection, retire, non-admin denied

## How it was built

**Retirement writes two columns, always together.** `LabPcController::destroy()` sets `status = retired` *and* soft-deletes; `restore()` reverses both. `retired` is absent from the form dropdown and rejected by `LabPcRequest` — it is the Retire button's business, not a value you choose. Write one without the other and the machine is retired in a report and operational on screen.

An access point has no equivalent: `ApStatus::Maintenance` means "temporarily off", not "gone", so AP removal is the soft delete alone.

**`->withTrashed(['show'])` on both resource routes is load-bearing.** It is what makes "keeps its history reachable" true — without it the model binding 404s the moment a machine is retired, on exactly the records the retirement was meant to preserve. There is a test pinning this.

**Counting a location's assets uses `withTrashed()`.** The soft-delete scope hides a retired PC from the relation, so counting without it reports the location empty and then hands the user a raw foreign-key error instead of the message written for that case.

**Where later modules plug in.** Both show pages carry labelled empty cards for tickets (M4), telemetry (M5) and risk (M7). Filling one means replacing an `<x-empty-state>`, not restructuring the page.
