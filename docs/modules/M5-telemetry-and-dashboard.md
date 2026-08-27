# M5 — Telemetry and AP Dashboard

**Track:** B · **Owner:** *unassigned* · **Sprint:** 2–3 · **Depends on:** M2 · **Blocks:** M7

> **The hardest module, and the project's headline feature.** Give it to whoever is strongest with data. It cannot slip.

## Purpose

Ingest access point telemetry through a swappable driver, classify AP health, and present it as a near-real-time dashboard plus historical congestion logs.

## Requirements covered

FR-209, FR-301, FR-302, FR-303, FR-304, FR-305, FR-501, FR-502, FR-504, FR-508 · NFR-02, NFR-07

## Tables owned

`telemetry_readings` · `telemetry_hourly`

## Components

| Kind | Items |
|---|---|
| Models | `TelemetryReading`, `TelemetryHourly` |
| Interface | `TelemetrySource` |
| Drivers | `SimulatedTelemetrySource`, `SnmpTelemetrySource` (stub) |
| DTO | `ReadingData` |
| Services | `TelemetryService` — store, classify, current status, history |
| Controllers | `Engineer\DashboardController`, `Api\DashboardDataController`, `Engineer\TrafficLogController` |
| Commands | `atlas:collect-telemetry`, `atlas:aggregate-telemetry`, `atlas:prune-telemetry` |
| Components | `<x-chart>`, `<x-stat-tile>` |

## Screens

Engineer: AP health dashboard (tiles + status grid, polling) · AP detail with 24h chart · traffic and congestion logs with date range
Assistant: AP status, read-only

## Key decisions

- **The driver swap is the whole design.** Read [../07-data-strategy.md](../07-data-strategy.md) and [ADR-0002](../decisions/ADR-0002-telemetry-source-driver.md) before writing a line.
- **Polling, not WebSockets.** See [ADR-0005](../decisions/ADR-0005-ajax-polling-not-websockets.md). Poll interval from config.
- **Charts read `telemetry_hourly`, not raw readings.** At 40 APs × 1 reading/minute, raw telemetry is ~1.7M rows a month. Charting off it will be visibly slow during the demo.
- **Classification is a service method, not a database trigger**, and uses `consecutive_breach` so one noisy sample cannot flip an AP's state.
- **Write the simulator properly.** Daily curve, weekday/weekend, per-AP baselines, occasional offline events, and latency correlated with saturation. If latency is uncorrelated noise, M7 has nothing real to find.
- **Backfill historical telemetry at a coarser interval** (5 minutes) using chunked `insert()`, not `create()` in a loop.

## Open questions

- [Q1](../04-open-questions.md) — the degraded/offline thresholds
- [Q9](../04-open-questions.md) — per-AP history, campus-wide trends, or both
- [Q10](../04-open-questions.md) — retention period

## Definition of done

- [ ] `TelemetrySource` interface with both implementations; swap works via `.env` alone
- [ ] Simulator produces plausible, internally consistent data with visible daily peaks
- [ ] Collector runs on schedule and writes one reading per active AP
- [ ] Classification correctly marks degraded and offline per configured thresholds
- [ ] Dashboard updates without page reload and shows last-updated time
- [ ] AP detail chart renders 24h from aggregates in under 2 seconds (NFR-02)
- [ ] Aggregation and pruning commands work and are idempotent
- [ ] An AP going offline notifies M4 exactly once
- [ ] Unit tests on classification; feature test on the polling endpoint
- [ ] DFD 1, data dictionary and flowchart drafted
