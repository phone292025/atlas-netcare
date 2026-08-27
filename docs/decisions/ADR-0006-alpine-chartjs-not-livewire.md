# ADR-0006: Use Alpine.js and Chart.js, not Livewire

**Status:** Accepted
**Date:** 2026-08-26
**Deciders:** Group Atlas

## Context

The approved proposal names Laravel, Blade, Tailwind and MySQL. It does not specify how client-side interaction is handled.

We need dropdowns, modals, a collapsing mobile sidebar, expanding remark fields on the checklist, a poll timer, and several charts. Seven students are learning Laravel simultaneously; the implementation rubric rewards a system that runs "complete without any error".

## Decision

- **Alpine.js** for client-side interaction, loaded via Vite alongside Tailwind.
- **Chart.js** for all charts, wrapped in a single `<x-chart>` Blade component taking a data prop.

No React, no Vue, no Livewire, no Inertia.

## Consequences

**Easier**
- Alpine is HTML attributes. A member who knows HTML can contribute a working dropdown the same day.
- Blade stays the single rendering path, so there is one mental model for the whole team.
- Chart.js is well documented and produces charts good enough to screenshot straight into the report.
- Both are small and have no build complexity beyond the Vite setup Tailwind already requires.

**Harder**
- Complex interactive forms need more hand-written JavaScript than Livewire would.
- No automatic server-state synchronisation; the dashboard poll is written by hand (ADR-0005).

## Alternatives considered

**Laravel Livewire.** Genuinely attractive — reactive components in PHP, and `wire:poll` would give us ADR-0005 for free. Rejected because it is a second mental model layered on a framework the team is still learning, its failure modes are unintuitive, and debugging it under deadline pressure is a risk we cannot price. Revisit for a future project, not this one.

**Vue or React SPA.** Rejected: contradicts the Blade-based stack in the approved proposal, and doubles the work by requiring an API for everything.

**Plain Blade with no JavaScript.** Rejected: the dashboard and checklist would feel dated, and originality is explicitly in the implementation rubric.
