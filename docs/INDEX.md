# Documentation index

> **Last updated:** 2026-11-01 · **All seven modules are built.** 2.0 is done through roadmap task 1.6: network devices, probe agents and the ingest API, and the Unknown status.

This folder documents the system. Conversation history and memory do not survive, so anything that matters beyond today belongs here.

---

## Read these four first

Twenty minutes, and you will be able to work on any part of it.

| # | Document | What you get |
|---|---|---|
| 1 | [01-requirements.md](01-requirements.md) | What the system must do, with traceable IDs, and which requirements are finished |
| 2 | [03-architecture.md](03-architecture.md) | How the code is organised, and why it is organised that way |
| 3 | [04-database.md](04-database.md) | Every table, column and relationship |
| 4 | [09-how-the-system-works.md](09-how-the-system-works.md) | The whole system end to end, written for somebody who did not build it |

Then read the module spec for whatever you are touching: [modules/](modules/).

---

## The system

| Document | Contents |
|---|---|
| [01-requirements.md](01-requirements.md) | Functional and non-functional requirements. The status column records how each was agreed; a section at the top records what is actually built |
| [02-open-questions.md](02-open-questions.md) | Eleven things the proposal left undefined. Ten are settled, and each records what was decided and why |
| [03-architecture.md](03-architecture.md) | Layers, request lifecycle, service boundaries, scheduled commands |
| [04-database.md](04-database.md) | Schema, indexes, soft deletes, what differs on PostgreSQL, and the MariaDB traps that shaped the schema |
| [05-data-strategy.md](05-data-strategy.md) | How demonstration data is tagged and removed, and how simulated telemetry is marked |
| [06-ui-conventions.md](06-ui-conventions.md) | The component vocabulary. Read before adding a screen, or you will invent a fourth button style |
| [07-testing.md](07-testing.md) | Test strategy, plus four habits learned by shipping defects through a green suite |
| [08-design-decisions.md](08-design-decisions.md) | Roughly forty decisions made during the build that the proposal never specified |
| [10-simulated-and-real-data.md](10-simulated-and-real-data.md) | What is simulated, what is real, and what would replace it |
| [11-installation-guide.md](11-installation-guide.md) | Installing and running from a clean clone, including what each failure looks like |
| [12-system-diagrams.md](12-system-diagrams.md) | Context Diagram, DFD Level 0, consolidated ERD and System Hierarchy Chart, as Mermaid source |
| [13-code-map.md](13-code-map.md) | Every file in the project and what it does. The index, when you know what you want but not where it lives |
| [roadmap.md](roadmap.md) | The plan for Atlas 2.0: real network monitoring, PostgreSQL, Docker, incidents and on-call, in phases |
| [glossary.md](glossary.md) | Terms that mean something specific here |

---

## Decisions and modules

| Folder | Contents |
|---|---|
| [decisions/](decisions/) | Seventeen architecture decision records, 0010 to 0017 for 2.0. Each says what was chosen, what was rejected, and what it costs |
| [modules/](modules/) | One spec per module, M1 to M7. Components, screens, requirements covered, definition of done |
| [progress/](progress/) | Build log, decision log, and what is still outstanding |

---

## Where the build is

| Document | Use it for |
|---|---|
| [progress/build-log.md](progress/build-log.md) | Session handover. Read first in a new development session |
| [progress/decision-log.md](progress/decision-log.md) | Every decision with a date and a reason, newest first. Nothing is deleted, only superseded |

---

## Rules that survive

Two, and both were learned the hard way.

**Never delete a resolved question or a superseded decision.** Change its status and record what replaced it. The history records why things are the way they are.

**A document that contradicts the code is worse than no document.** Six of these had drifted out of step by 2026-08-30, including one claiming a command existed that had never been written. If you change behaviour, change the document in the same commit.
