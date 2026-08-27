# ADR-0007: Score maintenance risk with weighted factors, not machine learning

**Status:** Accepted
**Date:** 2026-08-26
**Deciders:** Group Atlas

## Context

The case study category we are answering is "Predictive Maintenance System for Campus Facilities Using Data Analytics" — anticipating equipment failures before they occur. Module M7 must deliver genuine prediction, not just monitoring, or the project does not align with the brief.

The presentation rubric rewards "in-depth understanding of the codes / work done". Whatever we build, every member must be able to explain it.

We have no historical failure dataset to train on. Our data will be simulated telemetry plus seeded inspection and ticket history.

## Decision

A weighted-factor scoring model, with weights in `config/atlas.php`.

For a lab PC:

| Factor | Normalised input |
|---|---|
| Age | years in service / expected 6-year life |
| Fault frequency | tickets raised in the last 90 days / 10 |
| Critical failure rate | proportion of critical checklist items failed |
| Inspection lag | days since last inspection / 30 |

Each factor is clamped to 0..1, multiplied by its configured weight, summed and scaled to 0–100, then banded Low / Medium / High / Critical.

For an access point, the factors become trend-based: slope of mean latency and packet loss over 14 days, count of offline events, and peak client saturation.

The contributing factors are stored as JSON on `risk_assessments` and **displayed in the UI**, so a user can see why an asset scored what it did.

## Consequences

**Easier**
- Runs in milliseconds, needs no library, and adds no dependency.
- Every number is defensible. "This PC scored 78 because it is five years old, failed three critical checklist items last month, and has not been inspected in six weeks" is a complete answer.
- Weights are tunable from config during the demo without touching code.
- Showing the factors turns a number into analytics, which is what the category actually asks for.

**Harder**
- The weights are our judgement, not derived from data. We must say so in the report's Assumptions section.
- It cannot discover a pattern we did not think of. Accepted — with simulated data there is no genuine pattern to discover.

## Alternatives considered

**A trained model (regression, decision tree, or similar).** Rejected: no real failure data to train on, so any model would be trained on our own simulator's assumptions — circular and indefensible. It would also be a black box that most of the team could not explain under questioning.

**Simple threshold rules ("older than 5 years = high risk").** Rejected: not analytics, and it produces no ranking, which is the useful output.

**No predictive module at all.** Rejected: it is the module that aligns the project with Case Study Category 4. See [../01-brief-and-marking.md](../01-brief-and-marking.md).
