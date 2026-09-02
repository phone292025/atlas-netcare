# Documentation index

> **Last updated:** 2026-09-02 · **All seven modules are built. What remains is the report.**

This folder documents the system. Conversation history and memory do not survive, so anything that matters beyond today belongs here.

**Coursework administration lives in [`../coursework/`](../coursework/), not here.** The marking brief, module ownership, the roadmap, the submission checklist and the weekly progress reports moved out on 2026-09-02. They are still needed for the submission. They just are not documentation of how the system works, and mixing the two made both harder to find.

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
| [04-database.md](04-database.md) | Schema, indexes, soft deletes, and the MariaDB traps that have already cost this project |
| [05-data-strategy.md](05-data-strategy.md) | How demonstration data is tagged and removed, and how simulated telemetry is marked |
| [06-ui-conventions.md](06-ui-conventions.md) | The component vocabulary. Read before adding a screen, or you will invent a fourth button style |
| [07-testing.md](07-testing.md) | Test strategy, plus four habits learned by shipping defects through a green suite |
| [08-design-decisions.md](08-design-decisions.md) | Roughly forty decisions made during the build that the proposal never specified. Worth going through as a group before the viva |
| [10-simulated-and-real-data.md](10-simulated-and-real-data.md) | What is simulated, what is real, and what would replace it. Written to be lifted into the report's Limitations and Assumptions sections |
| [11-installation-guide.md](11-installation-guide.md) | Installing and running from a clean clone, including what each failure looks like |
| [12-system-diagrams.md](12-system-diagrams.md) | Context Diagram, DFD Level 0, consolidated ERD and System Hierarchy Chart, as Mermaid source |
| [glossary.md](glossary.md) | Terms that mean something specific here |

---

## Decisions and modules

| Folder | Contents |
|---|---|
| [decisions/](decisions/) | Eight architecture decision records. Each says what was chosen, what was rejected, and what it costs |
| [modules/](modules/) | One spec per module, M1 to M7. Components, screens, requirements covered, definition of done |
| [progress/](progress/) | Build log, decision log, and what is still outstanding |

---

## Where the build is

| Document | Use it for |
|---|---|
| [progress/build-log.md](progress/build-log.md) | Session handover. Read first in a new development session |
| [progress/decision-log.md](progress/decision-log.md) | Every decision with a date and a reason, newest first. Nothing is deleted, only superseded |
| [progress/what-is-left.md](progress/what-is-left.md) | Everything outstanding, checked against the code rather than recalled |

---

## Rules that survive

Two, and both were learned the hard way.

**Never delete a resolved question or a superseded decision.** Change its status and record what replaced it. The history is the evidence of design reasoning, and a marker asking "why did you do it this way" is asking for exactly that.

**A document that contradicts the code is worse than no document.** Six of these had drifted out of step by 2026-08-30, including one claiming a command existed that had never been written. If you change behaviour, change the document in the same commit.
