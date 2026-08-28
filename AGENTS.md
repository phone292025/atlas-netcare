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
| **Phase** | 2 — System Design (**design freeze 15 Sep 2026, diagrams not started**), building modules in parallel |
| **Build stage** | Sprint 0 complete. **Modules: 4 of 7 done** (M1 identity, M2 assets, M3 inspections, M4 ticketing). Next is M5. See `docs/progress/build-log.md` — **read it before doing anything.** |
| **Code written** | Sprint 0 + M1–M4. Config, enums, role middleware, app shell, 23 components, schema, seeders, account management, the audit log, the asset inventory, checklists with inspections, and ticketing. 387 tests passing. |
| **Last updated** | 2026-08-28 |
| **Blocking** | **Q6** only. Q3 was adopted-as-proposed and built in M4, but the team never agreed it — confirm or change it. See `docs/04-open-questions.md`. |

## Stack

Pinned versions, verified in the installed lock file. Do not upgrade any of these without an ADR.

| | Version | Notes |
|---|---|---|
| **Laravel** | 12.68.0 | Pinned. Laravel 13 needs PHP ^8.3; this project runs on PHP 8.2.12. See ADR-0008. |
| **PHP** | 8.2.12 | XAMPP |
| **Breeze** | 2.4.2 | Blade stack. Registration deliberately removed. |
| **Blade** | — | Server-side templating. All 23 shared components live in `resources/views/components/` — see them at `/dev/gallery`. |
| **Tailwind CSS** | 3.4.19 | Configured in `tailwind.config.js`, **not** the CSS-based v4 config |
| **Alpine.js** | 3.16 | Dropdowns, modals, the mobile drawer, the poll timer. No plugins installed. |
| **Chart.js** | via `window.Chart` | Wired in `resources/js/app.js` with shared defaults |
| **Database** | MariaDB 10.4.32 | `DB_CONNECTION=mariadb` (Laravel 12 has a dedicated driver). Matters for M7: MariaDB implements `JSON` as `LONGTEXT`. |

No React, no Vue, no Livewire, no Inertia. If a task seems to need one of those, it does not — raise it as an open question instead.


---

## Where to find things

| If you are about to… | Read first |
|---|---|
| Do anything at all | `docs/INDEX.md` |
| Write a migration or model | `docs/06-database.md` |
| Add a controller, route, or service | `docs/05-architecture.md` |
| Touch anything to do with mock/fake/seeded data | `docs/07-data-strategy.md` |
| Build a screen or Blade component | `docs/08-ui-conventions.md`, then look at `/dev/gallery` — the component probably already exists |
| Give something a status colour | `app/Support/Tone.php` — one method per surface, and nowhere else |
| Work on one specific feature area | `docs/modules/` — find its owner and spec. Progress tracker: `docs/progress/build-log.md` |
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
- **Never write a `match` on a tone outside `app/Support/Tone.php`.** One file owns tone to Tailwind classes, one method per surface. A second mapping is how `urgent` becomes two different reds.
- **Never use MySQL's `ENUM` column type.** Use a `string` column cast to a PHP backed enum. See ADR-0004.
- **Never use `float` for a number a user reads.** Use `decimal`.
- **Never add a global Eloquent scope** that hides demo rows. It will silently hide data during a demo.
- **Never install a package** without an ADR justifying it.
- **Never write `register` routes or self-signup.** Accounts are created by administrators only.
- **Never build a Tailwind class from a variable.** `"bg-{$colour}-50"`, or prefixing a class string with `str_replace`, produces a name Tailwind's scanner never sees. The class is purged from the build and the element renders **completely unstyled, with no error anywhere**. Write every class out in full. *This trap has been hit twice — once in the very file that documents it.*
- **Never nest a Blade comment inside a Blade comment.** The inner closing token ends the outer one, and everything after it becomes live template code. A usage example in a component docblock then gets compiled and evaluated. Keep examples as plain indented text.
- **Never run `composer update`.** It drifts dependencies off the committed lock file and can pull a Laravel version ADR-0008 rules out. Use `composer install`.
- **Never let a component query the database.** Components receive data as props. That is the single property that lets `ATLAS_TELEMETRY_DRIVER` change without a view being touched.
- **Never put a privilege column in `$fillable`.** `role`, `is_active` and `is_demo` on `User`, and `risk_score` / `risk_band` / `is_demo` on the asset models, are written by the system — never from request input. A mass-assigned `role` turns any profile form into privilege escalation.

---

## Before you commit

Four commands, in this order. Each catches something the others do not.

```bash
php artisan view:clear     # compiled views are Tailwind "source" — see below
npm run build
vendor/bin/pint            # the project has no pint.json; the Laravel preset is the standard
php artisan test --display-deprecations
```

**Why `view:clear` first.** `./storage/framework/views/*.php` is in the Tailwind `content` array, so **compiled Blade views count as source**. Build output therefore depends on view-cache state, and a deleted view keeps contributing classes until its compiled artifact is cleared. Measured on identical source: 42 KB cleared, 54 KB cached.

**Why `--display-deprecations`.** Warnings print above the summary line. Filtering test output down to `PASS|FAIL|Tests:` hides them — that is how five PHPUnit deprecations went unnoticed for a whole stage.

## Verifying your work

**A green test suite does not mean the page works.** Feature tests assert on rendered HTML; they cannot evaluate CSS, layout or JavaScript. Two real defects have shipped past a fully green suite:

- a sidebar that sat **off-screen on every desktop screen** — the class was in the markup, it just lost the cascade
- an authorisation guard that **failed open** — the test assigned a PHP `false` where the database returns `int(0)`

**A route whose tests only assert failures has never been rendered.** M3 shipped three 500s onto routes that all had tests — every one of which asserted a 403 or a redirect, so no test ever fetched the page successfully. Before calling a screen done, check that at least one test asserts `assertOk()` on it, in its *empty* state as well as its populated one. Half of these bugs only appear when a collection is empty, which is what every screen looks like on the day it ships.

For anything visual or stateful: open it, and **measure the DOM rather than trusting a screenshot**. When the browser pane is not displayed its compositor pauses, so screenshots can be stale frames and CSS transitions never advance. `getBoundingClientRect()` tells the truth; a picture may not.

`/dev/gallery` renders every component and is registered only when `APP_ENV=local`. Add to it rather than building a throwaway review page — that scaffold has been built and deleted twice already.

## Editing files from the shell

`sed` and `perl` **silently mangle PHP namespaces and Blade syntax**. `\E`, `\U`, `\C` and `$var` are all interpreted, so `App\Enums\UserRole` comes out as `AppnumsSERROLE` and the command still reports success. This has happened six times in this project.

Use an editor tool for anything containing `\` or `$`. If you must use the shell, write backslashes as `\x5c` — and always print the result back to check it applied.

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
