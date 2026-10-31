# M5 — Telemetry and AP Dashboard

**Depends on:** M2 · **Blocks:** M7

> **Last updated:** 2026-11-01

> **The headline feature, and the module 2.0 rebuilds.** Phase 1 of [../roadmap.md](../roadmap.md) replaced simulated pull with readings pushed by probe agents. Tasks 1.1 to 1.6 are done; the Go agent (1.7) is next.

## Purpose

Take in telemetry from agents (probe agents on a real network, and the simulator's virtual agent), classify device health, and present it as a near-real-time dashboard plus historical congestion logs.

## Requirements covered

FR-209, FR-301, FR-302, FR-303, FR-304, FR-305, FR-312, FR-313, FR-314, FR-501, FR-502, FR-504, FR-508 · NFR-02, NFR-07

## Tables owned

`agents` · `telemetry_readings` (a TimescaleDB hypertable) · `telemetry_hourly` (a continuous aggregate the database derives from the readings, not a table any code writes)

## Components

| Kind | Items |
|---|---|
| Models | `Agent`, `TelemetryReading`, `TelemetryHourly` |
| Simulator | `Simulator` — the virtual agent's rounds · `SimulatedReadings` — the model behind its numbers |
| Ingest | `ReadingIngest` takes one batch from a probe agent · `AgentService` registers agents, issues tokens, and records contact and silence · `AuthenticateAgent` and `LimitBodySize` guard the route · `IngestReadingsRequest` checks the batch's shape |
| DTO | `ReadingData` |
| Services | `TelemetryStorage` — TimescaleDB's compression, retention and hourly refresh jobs · `TelemetryService` — store, classify, current status, history · `TelemetryCollector` — one collection round plus its tickets and alarms, shared by the command and the Generate sample button · `NetworkMonitorService` — the Network monitor snapshot · `SimulationService` — take a sample, force a scenario |
| Enum | `SimulationScenario` — congestion, outage, feed interruption |
| Controllers | `Engineer\DashboardController`, `Api\DashboardDataController`, `Api\ReadingIngestController`, `Engineer\TrafficLogController`, `NetworkMonitorController`, `Admin\AgentController` |
| Commands | `atlas:collect-telemetry`, `atlas:backfill-telemetry`, `atlas:telemetry-storage` (at app start) |
| Components | `<x-chart>`, `<x-stat-tile>` |

## Screens

Engineer: dashboard (their tickets, what is waiting, and a live AP health panel) · access point list (tiles + table, polling, worst first) · Network monitor with simulation controls · AP detail with 24h chart · traffic and congestion logs with date range
Administrator: Network monitor with simulation controls · agents: register, rotate a token, retire
Assistant: AP status, read-only · Network monitor, read-only

An access point turns Degraded only after `consecutive_breach` readings in a row over the limits, so its latest reading can already be over them while it still says Online. The dashboard payload carries `over_threshold` and `breach` for that reading: `slow` for latency or packet loss, `busy` for clients over the saturation limit. The screens then say "Slow now" or "Busy now" in amber instead of a green Online next to a red figure, and count it as a problem. On the engineer's dashboard the access point panel says how many are healthy and draws every access point as a square under its room, green, amber for slow or busy, red for down, grey for maintenance, with a list of the ones that need attention and the figure behind each. The ticket lists beside it carry the same target line as the queue.

## Key decisions

- **Every reading comes from an agent.** The simulator is one too, so the demo and a real network share one way in. Read [../05-data-strategy.md](../05-data-strategy.md) and [ADR-0010](../decisions/ADR-0010-probe-agents-push-readings.md) before writing a line.
- **Polling, not WebSockets.** See [ADR-0005](../decisions/ADR-0005-ajax-polling-not-websockets.md). Poll interval from config.
- **Charts read `telemetry_hourly`, not raw readings.** At 40 APs × 1 reading/minute, raw telemetry is ~1.7M rows a month. Charting off it will be visibly slow during the demo.
- **Classification is a service method, not a database trigger**, and uses `consecutive_breach` so one noisy sample cannot flip an AP's state.
- **Write the simulator properly.** Daily curve, weekday/weekend, per-AP baselines, occasional offline events, and latency correlated with saturation. If latency is uncorrelated noise, M7 has nothing real to find.
- **Backfill historical telemetry at a coarser interval** (5 minutes) using chunked `insert()`, not `create()` in a loop.

### The Network monitor (added 2026-09-19)

- **A simulation scenario is not a status.** It changes what `SimulatedReadings` reports, from `simulation_started_at` onwards only, and the ordinary rules do the rest. Outage → unreachable → Offline on the next reading, one ticket, one alarm. Congestion → load over capacity → Degraded after `consecutive_breach` readings, not on the first, and one alarm (FR-305). An access point coming back from an outage passes through Degraded without alarming: its unreachable readings count as 100% loss. Recovery clears the column.
- **A feed interruption is left out of `Simulator::watched()`.** The path to the device failed, so nobody is listening, and `markStaleOffline()` only judges what somebody was listening to. It shows as unknown and never becomes an inferred outage. This is the same principle the 2026-09-09 `pollable()` change was written for.
- **A silent agent's devices are `unknown`, and that is written** (task 1.6, 2026-10-29). After `ingest.silent_after_minutes` without hearing from a probe agent, its Online and Degraded devices become Unknown and the engineers and administrators get one alert. No ticket: nobody knows the device is down. A reading changes it back, and the offline sweep leaves Unknown alone. The engineer dashboard draws it as a dashed square and lists it under "Needs attention".
- **"Stale / unknown" is a display state, never written.** An online or degraded access point whose newest reading is older than `offline_after_min` is shown as unknown, with its numbers hidden. A stopped collector therefore reads as "we do not know" rather than a confident Online from two weeks ago. Offline and maintenance are shown as they are.
- **The chart reads raw readings, grouped in SQL, as a deliberate exception to "charts read `telemetry_hourly`".** The monitor needs ten-minute points over the last hour and the current hour, which the hourly rollup has not reached yet. Grouped by the first 15 characters of the timestamp (`to_char` first on PostgreSQL, the column itself on SQLite), so at most 144 rows per access point leave the database. **Measured on the development database: 139 ms and 5 queries** for 29 access points over 6,032 raw rows. At one reading a minute the window holds about 42,000 rows; expect several hundred milliseconds, inside NFR-02. Re-measure if the estate grows.
- **The Generate sample button and `atlas:collect-telemetry` run the same `TelemetryCollector::tick()`.** Before, the ticket and alarm reactions lived in the command, and a sample taken from the browser would have skipped them.
- **Refused for a device a real agent watches.** There the controls would force fiction onto a real device. With nothing simulated the page hides them, and `SimulationService` refuses anyway.

## Open questions

- ✅ [Q1](../02-open-questions.md) — **settled**, built as assumed. Thresholds are five lines in `config/atlas.php`. Still worth sanity-checking 120 ms against a real APU access point.
- ✅ [Q9](../02-open-questions.md) — **settled**: both, from one screen.
- ✅ [Q10](../02-open-questions.md) — **settled**: 90 days raw, 12 months aggregated.

## Definition of done

- [x] The simulator and probe agents share one way in; a device moves between them by its **Watched by** field (ADR-0002's driver swap until 2026-10-28)
- [x] Simulator produces plausible, internally consistent data with visible daily peaks
- [x] Collector runs on schedule and writes one reading per device the simulator watches
- [x] Classification correctly marks degraded and offline per configured thresholds
- [x] Dashboard updates without page reload and shows last-updated time
- [x] AP detail chart renders 24h from aggregates in under 2 seconds (NFR-02)
- [x] Hourly summaries, compression and retention run as TimescaleDB jobs, and a backfill run twice stores nothing new
- [x] An AP going offline notifies M4 exactly once
- [x] Unit tests on classification; feature test on the polling endpoint

## How it was built

**The simulator is an agent (since 2026-10-28, roadmap task 1.5).** In 1.0 a driver chosen by `ATLAS_TELEMETRY_DRIVER` was pulled once a minute, and the real one, `SnmpTelemetrySource`, was a stub that threw. Now the simulator's virtual agent watches devices through `agent_id` like a probe agent does, and its readings go through `TelemetryService::record()`, which the ingest API calls too. A device a real agent watches can't be given a simulated fault or invented history, and with nothing simulated the controls don't appear.

**The simulator is a model, not `rand()`.** Each access point gets a fixed personality derived from its own id, so the same AP is consistently busier or consistently worse run after run — without that, "which AP is the problem?" has no stable answer and M7's ranking means nothing. On top of that: a two-humped daily curve with a lunch dip, weekends at ~28%, and latency rising with the **cube** of saturation. Measured on generated data: 3–4 clients and ~21 ms overnight, 31–33 clients and ~210 ms at the 11:00 peak. There are tests asserting the daily curve and the latency/saturation correlation, because without that correlation M7 has nothing real to detect.

**Classification needs history, so it lives in the service.** An agent reports numbers; deciding they amount to "degraded" needs the previous readings and the thresholds. `consecutive_breach` means one noisy sample cannot flip a colour or auto-raise a ticket, and an access point with too little history stays Online. A false Degraded on a newly added AP trains people to ignore the dashboard.

`NetworkStatus::Maintenance` survives classification untouched. It is an administrative decision, not a measurement; overwriting it would make the suppression last exactly until the next poll.

**Two guards stop the auto-ticket flooding.** The ticket is raised on the **transition** into offline, not on every offline reading, and `TicketService::hasOutstandingFor()` catches the rest. Without both, a six-hour outage files 360 tickets.

**Charts read `telemetry_hourly`.** 24 rows instead of 1,440. Since 2026-10-28 it is a TimescaleDB continuous aggregate the database derives from the readings, so no code writes it and it can't drift from them. Finished hours are stored and refreshed every 30 minutes over the last 7 days, so a late reading still lands in its hour; the newest hour is read live. See `docs/04-database.md`.

Latency averages **exclude offline readings**. An unreachable AP has no latency, and counting it as zero would make an outage read as the best hour that access point ever had.

**`atlas:backfill-telemetry` exists so the charts have something to draw.** A dashboard whose 24-hour chart is empty until you have left the collector running for a day is not demonstrable. It writes at 5-minute resolution through `TelemetryService::record()`, numbered like the simulator's own readings, so a second run stores nothing new. It covers only the devices the simulator watches, and refuses when there are none, rather than inventing history for a device a real agent measures.

**A collection gap fills itself.** The first round after the scheduler has been off writes simulated readings into the silence on the simulator's devices, up to `gap_fill_hours` back, and refreshes the hourly summaries over those hours, so the charts do not come back to a single point. A real agent's devices are never filled, because silence from real kit is a fact about the network.

## Running it

```bash
php artisan schedule:work                        # keep it live
```

In demo mode `migrate --seed` already writes two weeks of readings every five minutes, so a fresh install needs no backfill. A backfill skips any reading it already has, so it is for devices added after seeding. `--fresh` deletes every simulated reading first and rewrites them steady, which flattens the three degrading access points.

Without `schedule:work` the dashboard shows whatever the last manual collection produced and quietly goes stale — which looks exactly like a broken dashboard.
