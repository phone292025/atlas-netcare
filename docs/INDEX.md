# Documentation Index

> **Last updated:** 2026-08-30 · **Phase:** 2 — System Design

This folder is the project's source of truth. Conversation history and memory are not — they get lost. If something matters beyond today, it belongs in here.

---

## Read in this order

New to the project (human or AI agent), read these four in order. Twenty minutes total.

| # | Document | What you get |
|---|---|---|
| 1 | [01-brief-and-marking.md](01-brief-and-marking.md) | What the university requires and how marks are awarded |
| 2 | [03-requirements.md](03-requirements.md) | What the system must do, with traceable IDs |
| 3 | [05-architecture.md](05-architecture.md) | How the code is organised and why |
| 4 | [06-database.md](06-database.md) | Every table, column and relationship |

Then read the module spec for whatever you are working on: [modules/](modules/).

---

## Start here if you are writing the report or preparing for the viva

| Document | Contents |
|---|---|
| [13-how-the-system-works.md](13-how-the-system-works.md) | **The whole system, start to finish.** What each role does, how the parts fit, why they are built that way. Read this if you did not build it. |
| [12-what-the-proposal-did-not-say.md](12-what-the-proposal-did-not-say.md) | **Every decision made during the build that the proposal never specified.** Roughly forty of them, none agreed by the team yet. Go through it as a group before the viva. |
| [14-simulated-and-real-data.md](14-simulated-and-real-data.md) | **What is simulated, what is real, and what would replace it.** Written to be lifted straight into the report's Limitations and Assumptions sections, with the answer to "is any of this real?" already drafted. |

---

## Everything, by purpose

### Governance and compliance
| Document | Contents |
|---|---|
| [01-brief-and-marking.md](01-brief-and-marking.md) | Module brief, CLOs, deliverable weights, rubric criteria, guideline Sections E and F |
| [02-team-and-ownership.md](02-team-and-ownership.md) | Members, module ownership, tracks, communication rules, workload matrix |
| [11-submission-checklist.md](11-submission-checklist.md) | Every required artefact as a checkbox, mapped to the rubric |

### Requirements
| Document | Contents |
|---|---|
| [03-requirements.md](03-requirements.md) | Functional requirements (FR-###) by role, non-functional requirements (NFR-###), scope in/out |
| [04-open-questions.md](04-open-questions.md) | Q1–Q10 — what is still undecided, the working assumption, and who decides |
| [glossary.md](glossary.md) | Domain vocabulary. Seven people writing one document need one word per concept. |

### Design
| Document | Contents |
|---|---|
| [05-architecture.md](05-architecture.md) | Layers, request lifecycle, folder structure, coding conventions |
| [06-database.md](06-database.md) | Schema for all 17 tables, ERD, indexing and constraint conventions |
| [07-data-strategy.md](07-data-strategy.md) | How mock and real data coexist and how to switch between them |
| [08-ui-conventions.md](08-ui-conventions.md) | Layouts, Blade components, design tokens, status colours, responsive rules |
| [decisions/](decisions/) | Architecture Decision Records — the *why* behind every significant call |

### Building
| Document | Contents |
|---|---|
| [modules/](modules/) | One spec per module: owner, tables, routes, screens, definition of done |
| [09-roadmap.md](09-roadmap.md) | Two-track schedule, sprint contents, milestones, scheduling risks |
| [10-testing.md](10-testing.md) | Unit test approach, UAT protocol, test case format |
| [progress/build-log.md](progress/build-log.md) | **Where the build actually is.** Read first in any new development session. |
| [progress/](progress/) | Weekly progress reports and the running decision log |

---

## How to use this as an AI agent

1. **Load [`../AGENTS.md`](../AGENTS.md)** — the rules, conventions and prohibitions.
2. **Read [progress/build-log.md](progress/build-log.md)** — what exists, what is half-done, what is next. Conversation history does not survive between sessions; this file does.
3. **Check [04-open-questions.md](04-open-questions.md)** before assuming anything about behaviour that is not in the requirements.
4. **Check [decisions/](decisions/)** before proposing an architectural change — it may already have been considered and rejected, with reasons.
5. **Check the module spec** in [modules/](modules/) for whatever you are touching, and respect its owner.
6. **Update the docs in the same change** as the code. A schema change without a `06-database.md` update is an incomplete change.

## How to use this as a team member

- Bring [04-open-questions.md](04-open-questions.md) to every supervisor meeting — it is the agenda.
- Fill in your week in [progress/](progress/) before the weekly report deadline. It is directly marked.
- When you make a call that affects someone else, write it in [progress/decision-log.md](progress/decision-log.md). One line is enough.
- When your module is done, tick it off in [11-submission-checklist.md](11-submission-checklist.md).

---

## Conventions used in these documents

- **`FR-###` / `NFR-###`** — requirement IDs. Referenced by test cases and module specs.
- **`ADR-####`** — architecture decision record. Immutable once accepted; superseded rather than edited.
- **`Q#`** — open question. Status is one of `Open`, `Assumed`, or `Decided`.
- **`M#`** — module number, 1 to 7. Maps 1:1 to a team member.
