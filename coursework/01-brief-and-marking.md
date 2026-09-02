# Brief and Marking

> **Last updated:** 2026-08-26 · **Source:** module brief, project guidelines 2025, Test Specification Table, and the three rubric spreadsheets

This is the compliance source of truth. When deciding whether something is worth doing, check it against this file.

---

## Module

| | |
|---|---|
| **Code** | AAPP011-4-2 |
| **Title** | Capstone Project |
| **Programme** | APU Diploma |
| **Intakes** | UCDF2411ICT(SE), UCDF2411ICT(ITR) |
| **Supervisor** | Mr Dhason Padmakumar |
| **Duration** | 14 weeks — 4 proposal, 8 development, 1 finalisation, 2 presentation |
| **Group size** | 5–8 students (we are 7) |
| **Pass mark** | 50% overall |

## Learning outcomes

| CLO | Outcome | Assessed by |
|---|---|---|
| **CLO1** | Prepare a proposal to solve a project problem (A4, PLO6) | Proposal — 20% |
| **CLO2** | Work in team to complete the project within a set period (A3, PLO4) | Project Report — 40% |
| **CLO3** | Implement solution based on the proposal using appropriate design methodologies (C6, PLO2) | Project Solution — 40% |

## Mark allocation

From the Test Specification Table:

| Deliverable | Weight | Components |
|---|---:|---|
| **Proposal** | 20% | Proposal Plan (background, methodology, hierarchy chart, Gantt chart) 15% · Documentation 5% |
| **Project Report** | 40% | Final Report 35% · Documentation 5% |
| **Project Solution** | 40% | Implementation 30% · Presentation 10% |

### Report rubric breakdown (the 40%)

| Criterion | Marks | Notes |
|---|---:|---|
| Planning — problem, solution, objectives, scope, methodology, Gantt | 10 | Gantt must show a real task breakdown |
| Diagrams — Context, DFD 0, ERD | 15 | Correctness is explicitly marked |
| DFD 1, Data Dictionary, Flowchart | 20 | **Individual** components |
| Screen, Report & User Manual | 10 | Every design described as a user manual |
| Test Plan | 10 | Includes 4 documented UAT sessions |
| Significant Source Code | 5 | Snippets, highlighted and explained |
| Conclusion | 5 | Assumptions, limitations, enhancements |
| Format & Citation | 5 | APA 7. Every in-text citation must have a reference and vice versa. |
| Progress Report | 10 | **Weekly, from week 1 to week 12** |
| Independence / Deadlines | 10 | Managing work without excessive supervision |

### Solution rubric breakdown (the 40%)

| Criterion | Marks | Notes |
|---|---:|---|
| Implementation | 30 | Efficiency, validation, navigation, consistency. Neat, readable, **commented** code. |
| Presentation | 10 | Demonstrate the system, answer questions on technical and domain areas |

---

## Case study alignment

The brief requires selecting **one** of four system categories. Atlas NetCare answers:

> **Category 4 — Predictive Maintenance System for Campus Facilities Using Data Analytics**
> *"…leveraging data analytics to anticipate and address potential equipment failures before they occur. This approach can help improve the efficiency, reliability, and longevity of campus infrastructure."*

**This alignment must be stated explicitly in the report**, and it is why the predictive risk-scoring module (M7) is not optional. Without it the project is monitoring and ticketing, which is not predictive maintenance.

> ⚠️ **Action outstanding:** confirm in writing with Mr Dhason that Atlas NetCare is accepted as answering Category 4. Keep the reply as evidence.

### ICT(DI) requirement — does not apply

The brief requires ICT(DI) students to include reports and dashboards of descriptive statistics. **Neither of our intakes is ICT(DI)**, so this is not mandatory for us. We are building the analytics dashboards anyway because they strengthen the report and support Category 4.

---

## Required documentation content

### Section E — Proposal (submitted)

1. Cover page · 2. Table of Contents · 3. Workload Matrix · 4. Introduction (Project Background, Problem Context, Proposed Solution, Project Objectives, Project Scope) · 5. Project Plan with Gantt Chart · 6. System Hierarchy Chart · 7. Conclusion · 8. References · 9. Appendix (minutes)

### Section F — Final Report

The proposal is a subset of the final report. Required sections:

1. Cover page
2. Table of Contents
3. Acknowledgement
4. Abstract
5. Workload Matrix
6. Introduction — background, problem context, proposed solution, objectives, scope
7. Project Plan — system development methodology, Gantt chart
8. System Hierarchy Chart
9. Context Diagram
10. Data Flow Diagram Level 0
11. **Data Flow Diagram Level 1 — individual**
12. **Data Dictionary — individual**
13. Entity Relationship Diagram
14. **Pseudocode or Flowcharts — individual**
15. Screens Design and User Manual
16. Report Designs and User Manual
17. Test Plan — unit testing and user acceptance testing, with actual results, **4 UAT sessions documented**
18. Significant Source Codes — important snippets, highlighted and explained
19. Conclusion — assumptions, limitations, enhancements
20. References — APA
21. Appendix — all meeting minutes, plus an Installation Guide

> The three **individual** components (11, 12, 14) are why each module has exactly one owner. See [02-team-and-ownership.md](02-team-and-ownership.md).

---

## Non-negotiable rules

| Rule | Consequence of breaking it |
|---|---|
| **Referencing code from external sources requires APA citation *inside the source code***. | Treated as plagiarism. |
| Documentation must not normally exceed **50 pages**, excluding diagrams, tables, coding and minutes. | Marked down for non-compliance. |
| **TNR 12** recommended; consistent fonts, indentation, coding style and design style throughout. | Consistency is explicitly a marked criterion. |
| Every in-text citation has a reference; every reference is cited. | Direct hit on Format & Citation (5 marks). |
| Everything typed, including diagrams and tables. | Marked down. |
| Late submission scores **zero** unless an Extenuating Circumstances appeal is upheld. | Zero. |
| All supervisor communication goes through the **group leader**. | Process requirement. |
| Weekly progress reports from week 1 until at least week 12. | Worth 10 marks; missing reports cannot be backfilled credibly. |

## Weekly progress report format

Required contents (guidelines Section I):

1. **Section 1** — one-detail description of progress for **each member**
2. **Section 2** — any issues relating to project work
3. **Section 3** — minutes of group discussion: positive decisions made, problems solved
4. **Section 4** — scheduled workload of all members (Gantt or similar chart)

Template and log: [progress/](../docs/progress/).
