# ADR-0005: Refresh the dashboard by AJAX polling, not WebSockets

**Status:** Accepted
**Date:** 2026-08-26
**Deciders:** Group Atlas

## Context

FR-301 requires a "real-time access point health dashboard" and FR-302 requires it to update without a manual page reload.

Underlying telemetry is collected once per minute (FR-501), so the data itself changes at most once a minute. The system runs locally for the demo (ADR pending on hosting), driven by the Windows Task Scheduler.

Laravel offers Reverb for true WebSocket push.

## Decision

The dashboard polls a JSON endpoint on a timer.

- `GET /api/dashboard/live` behind `auth` and `role:engineer,admin`, returning current AP states and summary counts.
- An Alpine component fetches it every `config('atlas.poll_interval')` seconds, default 20, and redraws the tiles and Chart.js series.
- A subtle indicator shows when a refresh is in flight, and the last-updated time is displayed.

## Consequences

**Easier**
- No extra server process, no broadcasting configuration, no third connection to debug at 2am in week 11.
- Works identically on every member's machine and on any host.
- Trivially explainable in the viva, and easy to demonstrate by opening the network tab.

**Harder**
- Up to 20 seconds of staleness. Acceptable for data that changes once a minute.
- One HTTP request per client per 20 seconds. Irrelevant with a handful of concurrent users.
- "Real-time" in the report must be described honestly as near-real-time with a stated refresh interval.

## Alternatives considered

**Laravel Reverb / WebSockets.** Rejected: a persistent server process plus broadcasting config, for a dashboard whose data changes once a minute. The failure modes are harder to debug than the feature is worth, and it would not survive a move to basic shared hosting.

**Server-Sent Events.** Rejected: still needs a long-lived connection and PHP-FPM handles it poorly. Most of the cost of WebSockets, less of the benefit.

**Manual refresh button only.** Rejected: undercuts the "real-time telemetry dashboard" promised in the approved proposal.
