# Submission Checklist

> **Last updated:** 2026-08-30 · Tick items as they are genuinely complete, not as they are started.

## Where this actually stands

All seven modules are built and 579 tests pass. That is most of the code and a small share of the submission.

| Part | Weight | State |
|---|---|---|
| Proposal | 20% | Submitted. **31 fixes listed below** must not reach the report. |
| Project Report | 40% | **Barely started.** Two of twenty-one sections exist in any form. |
| Implementation and presentation | 40% | Code is close. Nothing rehearsed, nobody owns a module. |
| Weekly progress reports | part of the report mark | **1 written.** One per week to week 12. |

The three biggest jobs left are all writing, not coding.

**47 screens** need documenting for Section 15, Screens Design and User Manual. Every one, described as a manual.

**Four documented UAT sessions** with real participants and recorded feedback. These take calendar time, so they cannot be left to the last week.

**Four artefacts per member**, times seven members: a DFD Level 1, a data dictionary, a flowchart, and the ability to answer questions on their module. **Nobody owns a module yet**, so none of this can start.

---

## Part 1 — Proposal (20%) — SUBMITTED

The proposal is submitted, but the final report reuses it as a subset. **Fix these before they propagate into the report.**

### Blocking

- [ ] **State the case study category.** The brief requires choosing one of four. Atlas NetCare must explicitly claim **Category 4 — Predictive Maintenance System for Campus Facilities Using Data Analytics**, and the predictive module (M7) must exist to justify it. Confirm in writing with Mr Dhason.
- [ ] **Attach the official cover sheet.** `AAPP011-4-2 CP APU Alternative Assessment Cover.doc` must be page 1, completed with Group Number, Project Title, Intake, the Name/Signature table, and the signed plagiarism declaration. The current title page has none of these.
- [ ] **Rebuild the Gantt chart.** See [09-roadmap.md](09-roadmap.md) for the full list of faults in the current version.

### Referencing

- [ ] Add **Ohei & Brink (2021)** to the reference list — cited in the Introduction's first paragraph, missing from references
- [ ] Add **Stallings (2017)** to the reference list — cited at the end of the Atlas NetCare System section, missing from references
- [ ] Remove **Ulaby & Ravaioli (2019)**, *Fundamentals of Applied Electromagnetics* — never cited, and irrelevant to the project
- [ ] Remove or properly reference **Gember, Anand & Akella (2011)** — never cited
- [ ] Remove or properly reference **"View of The Effectiveness of Wi-Fi-Network Technology…"** — "View of" is a webpage artefact, no author, dated 2026
- [ ] Fix **Lin et al.** — cited as 2013 in text, listed as 2014 in references
- [ ] Fix **Mishra (2023)** in text → **(Mishra & Alzoubi, 2023)**
- [ ] Fix **Camacho, J., et al. (2020)** — APA 7 does not permit "et al." in a reference list entry
- [ ] Strip **`?utm_source=chatgpt.com`** from the Huang & Chang URL
- [ ] Replace the **Google search wrapper** around the Arteaga & Lucas DOI with the DOI itself
- [ ] Convert references from bullet points to **hanging indent**, alphabetical, no bullets
- [ ] Move **References before Appendix** (guidelines order: 8 then 9)

### Content and consistency

- [ ] Fix **"two distinct, yet interconnected, challenges"** — three are then listed
- [ ] Finish **Challenge 2, "Fragmented IT Lab Operations"** — currently ends mid-sentence in an ellipsis
- [ ] Finish the Introduction sentence ending **"With the internet itself"**
- [ ] Fix the date contradiction — title page says proposal finalised **26 July 2026**, but the minutes are dated 2, 7 and 15 August
- [ ] Fix **"Gnatt charts"** → "Gantt charts" (twice, Workload Matrix)
- [ ] Fix **"The Altas Members"** → "The Atlas Members" (Acknowledgement)
- [ ] Fix **"pak times"** → "peak times"
- [ ] Fix **"Shuthan"** → **"Suthan"** (three places)
- [ ] Fix **"i2.2 Methodology Justification"** — stray leading "i"
- [ ] Rewrite conversational passages in formal third person — §1.3 and the Project Aim especially
- [ ] Make objectives **measurable** — "reduce those frustrating delays" has no target
- [ ] Rebuild the **Workload Matrix** as an activity × member grid; "References" is not a work item
- [ ] Add a **Non-Functional Requirements** section using NFR-01 and NFR-02 from the Meeting 2 minutes
- [ ] Rename **"Proposal Background"** → "Project Background"; split "Proposed Solution / Scope" into two subsections; rename "Atlas NetCare System" → "System Hierarchy Chart"
- [ ] Note that **Kaung Khant Zin** appears in the Workload Matrix but is absent from Meeting 1 and 2 attendee lists

---

## Part 2 — Project Report (40%)

Guidelines Section F. **Bold = individual deliverable per member.**

- [ ] 1. Cover page
- [ ] 2. Table of Contents
- [ ] 3. Acknowledgement
- [ ] 4. Abstract
- [ ] 5. Workload Matrix
- [ ] 6. Introduction — background, problem context, proposed solution, objectives, scope
- [ ] 7. Project Plan — SDLC methodology, Gantt chart
- [x] 8. System Hierarchy Chart — **[12-system-diagrams.md](../docs/12-system-diagrams.md) Figure 4**, derived from the routes
- [x] 9. Context Diagram — **[12-system-diagrams.md](../docs/12-system-diagrams.md) Figure 1**
- [x] 10. Data Flow Diagram Level 0 — **[12-system-diagrams.md](../docs/12-system-diagrams.md) Figure 2**. Its numbered processes are the module numbers, so each member's DFD 1 is their process expanded
- [ ] 11. **Data Flow Diagram Level 1 — one per member**
- [ ] 12. **Data Dictionary — one per member**
- [x] 13. Entity Relationship Diagram — **[12-system-diagrams.md](../docs/12-system-diagrams.md) Figure 3**, all sixteen domain tables from the live schema
- [ ] 14. **Pseudocode or Flowcharts — one per member**
- [ ] 15. Screens Design and User Manual — every screen, described as a manual
- [ ] 16. Report Designs and User Manual
- [ ] 17. Test Plan — unit + UAT, with actual results and **4 documented UAT sessions**
- [ ] 18. Significant Source Codes — snippets highlighted and explained
- [ ] 19. Conclusion — Assumptions, Limitations, Enhancements — **[10-simulated-and-real-data.md](../docs/10-simulated-and-real-data.md) is written to be lifted straight into this section**, with live figures and the "is any of this real?" answer already drafted
- [ ] 20. References — APA 7
- [ ] 21. Appendix — all meeting minutes (**human input**) + Installation Guide — **[11-installation-guide.md](../docs/11-installation-guide.md) is written and verified against the running system; paste it in.**

### Formatting

- [ ] Under 50 pages excluding diagrams, tables, coding and minutes
- [ ] TNR 12, consistent throughout
- [ ] Uniform indentation and spacing
- [ ] Every diagram and table typed, none handwritten or photographed
- [ ] Every in-text citation has a reference; every reference is cited
- [ ] **Any external code in the source has an APA citation in a comment** — failure to do this is treated as plagiarism

---

## Part 3 — Implementation and Presentation (40%)

- [x] System runs complete, without errors, from a fresh install — 579 tests, `atlas:check-schema` clean, `atlas:mail-test` sends
- [ ] All functional requirements in [01-requirements.md](../docs/01-requirements.md) achieved or explicitly descoped with a reason — **M7's FRs are the remaining gap**
- [x] Validation on every form — every write goes through a Form Request
- [x] Consistent navigation across all three roles — one sidebar built from `config/atlas.php`
- [x] Code neat, readable and **commented** — explicitly in the rubric
- [ ] Installation Guide verified by someone who did not write it — **the README has never been followed by anyone else**
- [x] Demo database seeds reproducibly: `php artisan migrate:fresh --seed`
- [ ] Flagship flow rehearsed twice on the actual demo machine — inspection to pre-filled ticket is the flow
- [ ] Every member can explain their own module under questioning — **blocked: no module owners**
- [ ] Every member can answer "why did you build it this way?" from [decisions/](../docs/decisions/) and [08-design-decisions.md](../docs/08-design-decisions.md)

---

## Weekly, from week 1 to week 12

- [ ] Progress report submitted to the supervisor — 10 marks, see [progress/](../docs/progress/)
