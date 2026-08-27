# M7 — Predictive Risk and Reports

**Track:** B · **Owner:** *unassigned* · **Sprint:** 4 · **Depends on:** M3, M4, M5 · **Blocks:** nothing

> **This module is why the project answers Case Study Category 4.** Without it, Atlas NetCare is monitoring and ticketing — not predictive maintenance. It is not optional.

## Purpose

Score every lab PC and access point for likelihood of failure, rank them so maintenance can be prioritised, and produce the operational reports and descriptive statistics.

## Requirements covered

FR-309, FR-310, FR-311, FR-410, FR-505

## Tables owned

`risk_assessments` — and writes the cached `risk_score` / `risk_band` columns on `lab_pcs` and `access_points`

## Components

| Kind | Items |
|---|---|
| Models | `RiskAssessment` |
| Enums | `RiskBand` |
| Services | `RiskScoringService` — `scoreLabPc()`, `scoreAccessPoint()`, `recalculateAll()` |
| Controllers | `Engineer\RiskController`, `ReportController` |
| Commands | `atlas:recalculate-risk` |
| Components | `<x-risk-gauge>`, `<x-chart>` |

## Screens

Engineer: maintenance priority ranking (the module's headline screen) · asset risk detail showing contributing factors and risk history chart
All roles as permitted: reports — ticket volume by category and priority, mean resolution time, inspection pass rates, AP uptime and congestion by location, assets by risk band

## Key decisions

- **Weighted factors, not machine learning.** See [ADR-0007](../decisions/ADR-0007-weighted-risk-not-ml.md). Every number must be defensible in the viva.
- **Weights live in `config('atlas.risk_weights')`**, so they can be tuned live during the demo without a code change. That is a good moment to have prepared.
- **Store the factors as JSON and show them in the UI.** A bare score is a number; a score with "five years old, three critical failures last month, not inspected in six weeks" is analytics. This is the difference the rubric is looking for.
- **Keep assessment history.** One row per asset per run. It costs a few thousand rows and buys a risk-trend chart, which is one of the strongest figures available for the report.
- **Update the cached columns in the same transaction** as writing the assessment, or list views will disagree with detail views.

## Reports to build

| Report | Source | Why it matters |
|---|---|---|
| Assets by risk band | `risk_assessments` | The headline analytics figure |
| Ticket volume by category and priority | `tickets` | Descriptive statistics |
| Mean time to resolution by priority | `tickets` | Shows SLA is real |
| Inspection pass rate by location | `inspections` | Links M3 to outcomes |
| AP uptime and peak congestion by location | `telemetry_hourly` | Links M5 to outcomes |

## Definition of done

- [ ] `RiskScoringService` scores both asset types from configured weights
- [ ] Factors stored as JSON and rendered in the UI as readable reasons
- [ ] Maintenance priority screen ranks assets and links to detail
- [ ] Risk history chart shows an asset's score trending over time
- [ ] Preventive ticket can be raised directly from a high-risk asset
- [ ] All five reports render with charts and are screenshot-ready for the report
- [ ] Daily recalculation runs on schedule and updates cached columns consistently
- [ ] **Unit tests on `RiskScoringService`** — this is the most test-worthy code in the project
- [ ] DFD 1, data dictionary and flowchart drafted
