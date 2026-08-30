# Roadmap

> **Last updated:** 2026-08-30
>
> ## ⚠️ THE DATES BELOW ARE INVENTED AND ALMOST CERTAINLY WRONG
>
> **The module brief gives a duration — 14 weeks — and no calendar dates at all.** Every date in this file was produced by anchoring week 1 to 26 August 2026, the day these docs were written. That anchor was an assumption, and it was never checked with anybody.
>
> Phone Myat Ko has since said the real deadlines are **next year (2027)**. If that is right, every date here is out by roughly a year, including the "15 September design freeze" that other documents have been treating as imminent.
>
> **Do not plan against this file until the real dates are filled in.** Get them from the module brief or the supervisor, replace the table below, and delete this warning.
>
> The *sequence* and the *effort split* are still sound — two tracks, converging near the end, with a design freeze before the schema is depended on. It is only the calendar that is fiction.

Two tracks working in parallel on files that do not overlap, converging in week 11. This buys back the time a strictly sequential plan loses.

---

## Schedule

| Week | Dates | Track A — Operations | Track B — Data |
|---|---|---|---|
| **1–3** | 26 Aug – 15 Sep | *Design freeze.* Settle Q1–Q10, finalise ERD, agree conventions, draw Context Diagram and DFD 0, draft data dictionary skeleton. | |
| **4** | 16 – 21 Sep | **Sprint 0 — everyone together.** Repo and branch rules, Laravel + Breeze + Tailwind + Alpine, `config/atlas.php`, enums, role middleware, layout shell, shared Blade components, `ReferenceSeeder`. **Ends when all seven can run the app.** | |
| **5–6** | 22 Sep – 5 Oct | **M1** Auth, users, roles, audit log | **M2** Locations, lab PC and AP inventory |
| **7–8** | 6 – 19 Oct | **M3** Checklist templates, mobile inspection flow | **M5a** Telemetry interface, simulator, collector command |
| **9–10** | 20 Oct – 2 Nov | **M4** Ticketing end to end · **M6** Shifts, roster, alarms | **M5b** AP dashboard, polling endpoint, Chart.js, hourly aggregates |
| **11** | 3 – 9 Nov | **Converge.** M7 risk scoring, maintenance priority screen, reports and descriptive statistics. **Feature freeze at end of week.** | |
| **12** | 10 – 15 Nov | Integration, unit and feature tests, **4× UAT sessions with documented feedback**, bug fixing, Installation Guide, final documentation assembly. | |
| **13–14** | 16 – 29 Nov | Presentation rehearsal and delivery. Demo runs from a seeded database on a known-good machine. | |

## Milestones

| Date | Milestone | Gate |
|---|---|---|
| **15 Sep 2026** | **Design freeze** | ERD signed off by all seven. After this, schema changes need an ADR. |
| **21 Sep 2026** | **Sprint 0 complete** | Every member can clone, install, migrate, seed and run |
| **5 Oct 2026** | Foundation complete | M1 and M2 merged; other modules unblocked |
| **19 Oct 2026** | Core flows working | An inspection can be completed; telemetry is being collected |
| **2 Nov 2026** | All operational modules done | M1–M6 merged and working together |
| **9 Nov 2026** | **Feature freeze** | Only bug fixes and documentation after this |
| **15 Nov 2026** | Submission ready | All Section F artefacts complete |

---

## Sprint 0 checklist

Nobody builds a feature until every item is ticked. This week exists to prevent seven divergent codebases.

- [ ] Git repository created; `main` protected; branch naming agreed
- [ ] Laravel installed, `.env.example` committed with every `ATLAS_*` key
- [ ] Breeze installed; **`register` route removed**
- [ ] Tailwind + Alpine + Chart.js wired through Vite
- [ ] `config/atlas.php` created with all thresholds, weights, SLA hours and navigation
- [ ] All eleven enums created in `app/Enums/`
- [ ] `EnsureUserHasRole` middleware registered
- [ ] `layouts/app` and `layouts/guest` built; role-filtered sidebar working
- [ ] Shared Blade components built — see [08-ui-conventions.md](08-ui-conventions.md)
- [ ] Migrations for `users`, `locations`, `lab_pcs`, `access_points`
- [ ] `ReferenceSeeder` and empty `DemoDataSeeder`, wired to `demo_mode`
- [ ] `README` setup instructions verified by someone who did not write them
- [ ] **All seven members have run the app successfully**

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Track B is one person short** — three members carry inventory, telemetry and analytics, two of which are the hardest modules | High | High | Whoever finishes their Track A module first moves across in week 9. Flag in the weekly report the moment it slips. |
| **Q3 and Q6 unresolved at Sprint 0** | Medium | High | Both are on the agenda for the next supervisor meeting. Neither takes more than ten minutes to settle. |
| **Feature freeze slips past 9 Nov** | Medium | **Severe** | Week 12 is the only slack. If it slips, the 4 UAT sessions and Installation Guide get cut — and both are directly marked. Freeze on time even with one feature unfinished. |
| ~~**Simulator too simplistic** — uncorrelated noise gives M7 nothing to find~~ **Did not happen.** The simulator models a time-of-day curve and a weekday factor rather than plain noise, and M7's trend scoring reads it correctly. A DIFFERENT version of this risk did land: nothing scores High or Critical, because the seeded assets are neither old nor degrading. That is a data problem, not a simulator one. | — | — | Seed a few aged machines and a degrading access point before the demonstration. See [14-simulated-and-real-data.md](14-simulated-and-real-data.md) §1.4. |

| **Merge conflicts on shared files** | High | Low | `config/atlas.php`, layouts and `routes/web.php` change by announcement. Small, frequent PRs. |
| **A member falls behind silently** | Medium | High | Weekly progress report per member, and the module map makes it visible immediately. |
| **Demo fails on the day** | Low | **Severe** | Rehearse the flagship flow from a fresh `migrate:fresh --seed` on the actual demo machine, twice, in week 13. |

---

## Rebuilding the Gantt chart

The proposal's schedule needs replacing before the final report. Known faults in the submitted version:

- It is a **task table, not a Gantt chart** — no timeline bars
- **Every task shows 100% progress** on a document written before any work started
- Phase 3 is stated as 21 days but spans 16 Sep – 16 Oct (30 days)
- **Phase 4 starts 7 Oct while Phase 3 runs to 16 Oct** — an unexplained overlap
- "Bug Fixing & Final Presentation" appears as a sub-task of both Phase 4 and Phase 5
- A 5-day gap sits between Phase 1 ending and Phase 2 starting
- No section heading, no figure caption, no Table of Contents entry

Rebuild from the schedule above using MS Project, GanttProject or an Excel stacked-bar chart. Add a numbered heading, a figure caption, a ToC entry, and set progress honestly.
