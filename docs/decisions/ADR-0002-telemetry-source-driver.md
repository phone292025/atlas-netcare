> **What this decision means in practice is written up in [../10-simulated-and-real-data.md](../10-simulated-and-real-data.md).**

# ADR-0002: Telemetry enters the system through a swappable driver

**Status:** Accepted
**Date:** 2026-08-26
**Deciders:** Group Atlas

## Context

The proposal assumes APU's IT department will grant API access and network privileges to pull live telemetry from campus access points. For a student project touching production network infrastructure, that permission is unlikely to arrive, and it may not arrive in time either way.

The system has to run end to end without errors. The Wi-Fi telemetry dashboard is our headline feature. It cannot depend on a permission we do not control.

Separately, we do not yet know whether the final system will use real data, mock data, or both.

## Decision

Access point telemetry enters through a `TelemetrySource` interface with two implementations, selected by configuration:

- `SimulatedTelemetrySource` — generates plausible readings from a daily curve, per-AP baselines, and correlated latency and loss. Default.
- `SnmpTelemetrySource` — polls real access points. Stubbed until access exists.

The binding lives in `AppServiceProvider` and is driven by `config('atlas.telemetry_driver')`, set from `ATLAS_TELEMETRY_DRIVER` in `.env`. A scheduled command calls `collect()` and hands the results to `TelemetryService` for storage.

Every reading is stored with `source = 'simulated'` or `source = 'live'`.

## Consequences

**Easier**
- Switching to live data is a `.env` change plus one class. No controller, view, migration or test changes.
- The demo never depends on external permission or network availability.
- Both data kinds can coexist in one table and be charted or purged separately.
- Everything downstream has exactly one code path, so there is no "works with mock, breaks with real" class of bug.

**Harder**
- The simulator has to be good enough to be plausible. If latency is random noise uncorrelated with client count, the predictive module has nothing real to detect.
- If live SNMP access does arrive, credential storage becomes a problem we have not yet solved.

## Alternatives considered

**Wait for APU IT and build against real telemetry only.** Rejected: makes the headline feature depend on a third party's decision.

**Hardcode sample data in the controller.** Rejected: cannot be replaced later without rewriting the frontend, and is visibly a shortcut to anyone reading the code.

**Abstract every data source behind an interface.** Rejected: telemetry is the only external source. Everything else is entered by users through our own UI and is real the moment it is typed.
