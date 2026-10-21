# M7 — Predictive Risk and Reports

**Depends on:** M3, M4, M5 · **Blocks:** nothing

> **Last updated:** 2026-09-26

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
| Services | `RiskScoringService` — `scoreLabPc()`, `scoreAccessPoint()`, `recalculateAll()` · `Reports\ReportingService` — the five report figures |
| Controllers | `Engineer\RiskController`, `ReportController` |
| Commands | `atlas:recalculate-risk` |
| Components | `<x-risk-gauge>`, `<x-chart>` |

## Screens

Engineer: maintenance priority ranking (the module's headline screen) · asset risk detail showing contributing factors and risk history chart
All roles as permitted: reports — ticket volume by category and priority, mean resolution time, inspection pass rates, AP uptime and congestion by location, assets by risk band

## Key decisions

- **Weighted factors, not machine learning.** See [ADR-0007](../decisions/ADR-0007-weighted-risk-not-ml.md). Every number needs a stated reason.
- **Weights live in `config('atlas.risk_weights')`**, so they can be tuned live during the demo without a code change. That is a good moment to have prepared.
- **Store the factors as JSON and show them in the UI.** A bare score is a number; a score with "five years old, three critical failures last month, not inspected in six weeks" is analytics.
- **Keep assessment history.** One row per asset per run. It costs a few thousand rows and buys a risk-trend chart.
- **Update the cached columns in the same transaction** as writing the assessment, or list views will disagree with detail views.

## Reports to build

| Report | Source | Why it matters |
|---|---|---|
| Assets by risk band | `risk_assessments` | The headline analytics figure |
| Ticket volume by category and priority | `tickets` | Descriptive statistics |
| Mean time to resolution by priority | `tickets` | Shows SLA is real |
| Inspection pass rate by location | `inspections` | Links M3 to outcomes |
| AP uptime and peak congestion by location | `telemetry_hourly` | Links M5 to outcomes |

## Built on 2026-08-30

Everything below. 29 tests, and the scoring ones pin their own weights rather than reading config — a test that reads the same configuration as the code cannot detect a weight changing, which is the one thing these numbers need protecting from. The clamp and the renormalising were both checked by reintroducing the bug and confirming the tests fail.

**Three decisions worth being able to defend.**

*Missing data is not zero risk.* An unmeasurable factor is dropped and the remaining weights renormalised, so an asset is scored on what is known. The naive alternative makes a machine with no purchase date score 25% lower than an identical machine that has one, and sink in the ranking because nobody typed a date in. The stored breakdown is rescaled too, so the parts still sum to the total on screen.

*Never scored sorts last, not first.* Nothing is known about it. Floating an unknown to the top of a maintenance queue buries the assets that are genuinely about to fail. An access point with no telemetry is reported as unscored rather than as a clean zero, because silence is usually a dead device.

*Access points are scored on trends, lab PCs on history.* The two asset types leave different evidence, so `risk_weights` was restructured into one set each. A link that has always been slow is a known quantity somebody has decided to live with; a link that is getting slower is the prediction.

**The trend is measured as the second half's mean minus the first half's**, not a least squares regression. One subtraction anybody can check by hand, and over two weeks of hourly data it answers the same question. ADR-0007's whole argument is that every number has to survive being questioned.

**`risk_assessments.calculated_at` is `dateTime`, not `timestamp`.** That column is the x-axis of the trend chart, and MariaDB would have quietly moved it to "now" on every update.

## Definition of done

- [x] `RiskScoringService` scores both asset types from configured weights
- [x] Factors stored as JSON and rendered in the UI as readable reasons
- [x] Maintenance priority screen ranks assets and links to detail — both asset types in one queue
- [x] Risk history chart shows an asset's score trending over time — 90 days, needs two runs before it draws
- [x] Preventive ticket can be raised directly from a high-risk asset, pre-filled with the actual factors
- [x] All five reports render with charts
- [x] Daily recalculation runs on schedule and updates cached columns consistently — 02:00, after telemetry aggregation
- [x] **Unit tests on `RiskScoringService`** — 14 of them, one factor at a time

## Not built, and deliberately

**A spread across all four risk bands in the demo data.** Every seeded asset currently lands Low or Medium, because the demo data has no old machines and no degrading links. The scoring is right; there is nothing dramatic to score. **Worth fixing before the demonstration** — a maintenance ranking with no Critical row does not show what the screen is for.
