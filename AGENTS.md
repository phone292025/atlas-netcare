# Atlas NetCare — Agent Contract

> **Read `docs/INDEX.md` before doing anything substantial.** This file is the summary; the docs folder is the source of truth. If this file and `docs/` disagree, `docs/` wins and this file needs updating.

---

## What this project is

**Atlas NetCare** is a web-based campus network monitoring and IT operations portal, built as the final-year capstone project for **AAPP011-4-2 Capstone Project** at Asia Pacific University of Technology & Innovation (APU).

It serves three roles — Student IT Assistants, Network Engineers, and System Administrators — across four operational areas plus one analytics area:

1. Real-time Wi-Fi access point telemetry dashboard
2. Digital lab-PC maintenance checklists
3. Workspace incident ticketing
4. Automated duty rosters and shift alarms
5. Predictive maintenance risk scoring (the module that aligns the project to Case Study Category 4)

This is **academic coursework assessed against a published rubric**. Documentation quality, diagram correctness, and consistency are directly marked — they are not overhead. See `docs/01-brief-and-marking.md`.

## Current status

| | |
|---|---|
| **Phase** | 2 — System Design (design freeze 15 Sep 2026) |
| **Code written** | None yet. Design must be ratified first. |
| **Last updated** | 2026-08-26 |
| **Blocking** | Open questions Q3 and Q6 — see `docs/04-open-questions.md` |

## Stack

- **Laravel** (PHP) — backend, routing, auth, Eloquent ORM
- **Blade** — server-side templating
- **Tailwind CSS** — styling, via Vite
- **Alpine.js** — small client-side interactions (dropdowns, modals, poll timer)
- **Chart.js** — telemetry and analytics charts
- **MySQL** — single database, 17 domain tables

No React, no Vue, no Livewire, no Inertia. If a task seems to need one of those, it does not — raise it as an open question instead.

---

## Where to find things

| If you are about to… | Read first |
|---|---|
| Do anything at all | `docs/INDEX.md` |
| Write a migration or model | `docs/06-database.md` |
| Add a controller, route, or service | `docs/05-architecture.md` |
| Touch anything to do with mock/fake/seeded data | `docs/07-data-strategy.md` |
| Build a screen or Blade component | `docs/08-ui-conventions.md` |
| Work on one specific feature area | `docs/modules/` — find its owner and spec |
| Understand *why* something is built this way | `docs/decisions/` |
| Check whether a requirement exists | `docs/03-requirements.md` |
| Know what is still undecided | `docs/04-open-questions.md` |
| Use a domain word correctly | `docs/glossary.md` |

---

## Hard rules

These are not stylistic preferences. Breaking them creates work for six other people.

1. **No business logic in controllers.** A controller validates via a Form Request, calls one service method, and returns a view or JSON. If an action exceeds ~15 lines, the excess belongs in `app/Services/`.
2. **No database queries in Blade.** Views receive data as props. They never call `::all()`, `::where()`, or a relationship that triggers a query.
3. **No magic numbers.** Every threshold, weight, SLA hour, and interval lives in `config/atlas.php`. If you are about to type a number that represents a business rule, put it in config instead.
4. **One migration per table, never edited after merge.** Schema changes go in a *new* migration. Editing a merged migration silently breaks every teammate's local database.
5. **One module, one owner, one branch.** Check `docs/modules/README.md` before editing a file. Do not refactor another module's code without telling its owner.

## Naming conventions

| Thing | Convention | Example |
|---|---|---|
| Table | plural snake_case | `telemetry_readings` |
| Model | singular StudlyCase | `TelemetryReading` |
| Column | snake_case | `packet_loss_pct` |
| Foreign key | `{singular_table}_id` | `access_point_id` |
| Boolean column | `is_` or `has_` prefix | `is_demo`, `is_critical` |
| Timestamp column | past-tense verb + `_at` | `resolved_at`, `last_seen_at` |
| Percentage column | `_pct` suffix, `decimal(5,2)` | `channel_util_pct` |
| Route name | dot notation, role-prefixed | `engineer.tickets.show` |
| Blade component | kebab-case | `<x-status-badge>` |
| Service class | noun + `Service` | `TicketService` |
| Artisan command | `atlas:` prefix | `atlas:collect-telemetry` |
| Enum | singular StudlyCase, backed by string | `TicketStatus::InProgress` |

## Never do these

- **Never create a second database** for mock or test data. One schema, one database, tagged rows. See ADR-0003.
- **Never add a repository interface** for an Eloquent model. Eloquent is already the data-access layer. See ADR-0001.
- **Never hardcode arrays of fake data** in a controller, service, or Blade file. Seeders and factories only. See `docs/07-data-strategy.md`.
- **Never use MySQL's `ENUM` column type.** Use a `string` column cast to a PHP backed enum. See ADR-0004.
- **Never use `float` for a number a user reads.** Use `decimal`.
- **Never add a global Eloquent scope** that hides demo rows. It will silently hide data during a demo.
- **Never install a package** without an ADR justifying it.
- **Never write `register` routes or self-signup.** Accounts are created by administrators only.

---

## Keeping these docs true

Documentation that drifts is worse than none, because people trust it.

- **A decision that changes how code is written** → write an ADR in `docs/decisions/`, then update the affected doc.
- **A small call made in passing** → one line in `docs/progress/decision-log.md`.
- **A schema change** → update `docs/06-database.md` in the *same* commit as the migration.
- **A new requirement** → give it an ID in `docs/03-requirements.md`. Requirements without IDs cannot be tested or traced.
- **An open question resolved** → update its status in `docs/04-open-questions.md`; do not delete it. The history is evidence for the report.
- **Finishing a week** → fill in `docs/progress/week-NN.md`. This is worth 10% of the report mark.

When you change a doc, update the `Last updated` line at its top.

---

## About this file

`AGENTS.md` is the **canonical** agent contract. `CLAUDE.md` in the same folder is a pointer to it, kept only so that tools looking for that filename find their way here. Never copy rules into the pointer — one file, one source of truth.

Different coding tools load different filenames automatically. If yours loads neither, open this file at the start of every session before asking for any code. An agent working without it will produce something that looks right and violates three conventions.
