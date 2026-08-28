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

- ✅ [Q1](../04-open-questions.md) — **settled**, built as assumed. Thresholds are five lines in `config/atlas.php`. Still worth sanity-checking 120 ms against a real APU access point.
- ✅ [Q9](../04-open-questions.md) — **settled**: both, from one screen.
- ✅ [Q10](../04-open-questions.md) — **settled**: 90 days raw, 12 months aggregated.

## Definition of done

- [x] `TelemetrySource` interface with both implementations; swap works via `.env` alone
- [x] Simulator produces plausible, internally consistent data with visible daily peaks
- [x] Collector runs on schedule and writes one reading per active AP
- [x] Classification correctly marks degraded and offline per configured thresholds
- [x] Dashboard updates without page reload and shows last-updated time
- [x] AP detail chart renders 24h from aggregates in under 2 seconds (NFR-02)
- [x] Aggregation and pruning commands work and are idempotent
- [x] An AP going offline notifies M4 exactly once
- [x] Unit tests on classification; feature test on the polling endpoint
- [ ] DFD 1, data dictionary and flowchart drafted — **outstanding, and it is a marked deliverable.**

## How it was built

**The driver swap is real, and tested.** `AppServiceProvider` binds `TelemetrySource` from `config('atlas.telemetry_driver')`; a test asserts both implementations resolve. An unrecognised driver name **throws** rather than falling back to the simulator — a typo in `.env` silently producing invented data on a system meant to show real readings is the worst outcome available here.

`SnmpTelemetrySource` throws too, rather than returning an empty collection: forty access points appearing to go quiet at once would cost somebody an afternoon. Its docblock lists exactly what implementing it involves.

**The simulator is a model, not `rand()`.** Each access point gets a fixed personality derived from its own id, so the same AP is consistently busier or consistently worse run after run — without that, "which AP is the problem?" has no stable answer and M7's ranking means nothing. On top of that: a two-humped daily curve with a lunch dip, weekends at ~28%, and latency rising with the **cube** of saturation. Measured on generated data: 3–4 clients and ~21 ms overnight, 31–33 clients and ~210 ms at the 11:00 peak. There are tests asserting the daily curve and the latency/saturation correlation, because ADR-0002 says a sharp marker will look for it.

**Classification needs history, so it lives in the service.** A driver reports numbers; deciding they amount to "degraded" needs the previous readings and the thresholds. `consecutive_breach` means one noisy sample cannot flip a colour or auto-raise a ticket, and an access point with too little history stays Online — a false Degraded on a newly added AP trains people to ignore the dashboard.

`ApStatus::Maintenance` survives classification untouched. It is an administrative decision, not a measurement; overwriting it would make the suppression last exactly until the next poll.

**Two guards stop the auto-ticket flooding.** The ticket is raised on the **transition** into offline, not on every offline reading, and `TicketService::hasOutstandingFor()` catches the rest. Without both, a six-hour outage files 360 tickets.

**Charts read `telemetry_hourly`.** 24 rows instead of 1,440. Aggregation upserts against a unique `(access_point_id, hour_bucket)` index, so re-running it corrects rows rather than doubling every point on the chart — and it deliberately rebuilds the last three hours, because a late collection run would otherwise leave a permanently wrong bucket nothing revisits.

Latency averages **exclude offline readings**. An unreachable AP has no latency, and counting it as zero would make an outage read as the best hour that access point ever had.

**`atlas:backfill-telemetry` exists so the charts have something to draw.** A dashboard whose 24-hour chart is empty until you have left the collector running for a day is not demonstrable, and the demo is worth 40%. It writes at 5-minute resolution via chunked `insert()`, and refuses to run against the SNMP driver rather than inventing history and stamping it `live`.

## Running it

```bash
php artisan atlas:backfill-telemetry --days=14   # invent history, then aggregate
php artisan schedule:work                        # keep it live
```

Without `schedule:work` the dashboard shows whatever the last manual collection produced and quietly goes stale — which looks exactly like a broken dashboard.
