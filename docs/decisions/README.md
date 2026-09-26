# Architecture Decision Records

> **Last updated:** 2026-09-26

An ADR records a decision that shapes how code is written, why it was made, and what it costs. It exists so that in week 10 nobody re-opens a settled argument, and anyone on the team can explain any choice.

## Index

| # | Decision | Status |
|---|---|---|
| [0001](ADR-0001-modular-monolith-no-repositories.md) | Modular monolith on Laravel MVC, no repository pattern | Accepted |
| [0002](ADR-0002-telemetry-source-driver.md) | Telemetry enters through a swappable driver | Accepted |
| [0003](ADR-0003-single-database-demo-flag.md) | One database; demo data tagged, not separated | Accepted |
| [0004](ADR-0004-string-columns-not-mysql-enum.md) | String columns cast to PHP enums, not MySQL ENUM | Accepted |
| [0005](ADR-0005-ajax-polling-not-websockets.md) | AJAX polling for live updates, not WebSockets | Accepted |
| [0006](ADR-0006-alpine-chartjs-not-livewire.md) | Alpine.js and Chart.js, not Livewire | Accepted |
| [0007](ADR-0007-weighted-risk-not-ml.md) | Weighted-factor risk scoring, not machine learning | Accepted |
| [0008](ADR-0008-laravel-12-pinned.md) | Pin to Laravel 12, not the latest release | Accepted |
| [0009](ADR-0009-themed-design-system.md) | Rebuild the interface on themed tokens, Geist and Phosphor | Accepted |

## Rules

- **Numbered sequentially and never renumbered.** ADR-0004 is ADR-0004 forever.
- **Immutable once accepted.** If a decision changes, write a new ADR that supersedes the old one and update the old one's status to `Superseded by ADR-####`. Do not edit the original.
- **Status is one of:** `Proposed`, `Accepted`, `Superseded by ADR-####`, `Deprecated`.
- **Write one when:** adding a dependency, choosing between two viable technical approaches, deviating from a Laravel default, or making a call someone will later ask "why?" about.
- **Do not write one for:** naming a variable, picking a colour, or anything reversible in ten minutes. Those go in [../progress/decision-log.md](../progress/decision-log.md).

## Template

```markdown
# ADR-####: Short title in the imperative

**Status:** Accepted
**Date:** YYYY-MM-DD
**Deciders:** names

## Context
What situation forces a decision? What constraints apply?

## Decision
What we are doing, stated plainly.

## Consequences
What becomes easier. What becomes harder. What we accept as a cost.

## Alternatives considered
What else was on the table, and the specific reason it lost.
```
