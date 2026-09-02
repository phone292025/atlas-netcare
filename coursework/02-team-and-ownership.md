# Team and Ownership

> **Last updated:** 2026-08-30 · **Status:** ⚠️ **still nobody owns anything, and all seven modules are now built.** The 21 module diagrams and the three whole-system ones cannot start until this is filled in.

---

## Members

| Member | TP Number | Role |
|---|---|---|
| Yar Zar Min | TP086455 | **Group Leader** — sole channel to the supervisor |
| Swan Yee Htet | TP085967 | Developer |
| Thint Myat Htet Myat Shwe | TP086389 | Developer |
| Phone Myat Ko | TP086905 | Developer |
| Nyan Lin Tun | TP086590 | Developer |
| Sachin Nair A/L C. Suthan | TP087180 | Developer |
| Kaung Khant Zin | TP086063 | Developer |

> **Spelling note:** the surname is **Suthan**. The proposal spells it "Shuthan" in three places. Use "Suthan" consistently from here on — inconsistent naming is a marked consistency fault.

---

## Why one module per person

Guidelines Section F requires three **individual** deliverables:

- **DFD Level 1** (item 11)
- **Data Dictionary** (item 12)
- **Pseudocode or Flowcharts** (item 14)

Seven members, seven modules, one owner each. Each person documents the module they personally built, so the individual report components fall out of the work rather than being invented in week 12.

## Module assignment

Fill in the Owner column at the next team meeting, then mirror it into `README.md` and the Workload Matrix. Live build status is in [progress/build-log.md](../docs/progress/build-log.md).

| ID | Module | Track | Owner | Code | Diagrams for the report |
|---|---|---|---|---|---|
| **M1** | Identity, roles and audit log | A | *unassigned* | ✅ built | ⬜ DFD 1, data dictionary, flowchart |
| **M2** | Locations and asset inventory | B | *unassigned* | ✅ built | ⬜ DFD 1, data dictionary, flowchart |
| **M3** | Checklists and inspections | A | *unassigned* | ✅ built | ⬜ DFD 1, data dictionary, flowchart |
| **M4** | Workspace ticketing | A | *unassigned* | ✅ built | ⬜ DFD 1, data dictionary, flowchart |
| **M5** | Telemetry and AP dashboard | B | *unassigned* | ✅ built | ⬜ DFD 1, data dictionary, flowchart |
| **M6** | Shifts, roster and alarms | A | *unassigned* | ✅ built — FR-408 outstanding | ⬜ DFD 1, data dictionary, flowchart |
| **M7** | Predictive risk and reports | B | *unassigned* | ✅ built 2026-08-30 | ⬜ DFD 1, data dictionary, flowchart |

> **The code is finished and nobody owns anything.** That was survivable while modules were being built; it is not survivable now, because what remains is 21 diagrams that only a named person will produce. The three whole-system diagrams — Context, DFD 0 and the consolidated ERD — are worth **15 marks** and have no owner either.
>
> **Assigning these is the single most valuable thing the next team meeting can do.**

Specs: [modules/](../docs/modules/)

### Assignment guidance

- **M5 and M7 are the hard ones.** Give them to whoever is strongest with data and comfortable debugging. They also carry the Category 4 alignment, so they cannot slip.
- **M1 must be first and must be solid.** Every other module has foreign keys into `users`. Give it to someone reliable rather than someone fast.
- **M2 is the lightest.** Whoever owns it should expect to help M5 from week 9 — see the track imbalance note below.

## Tracks

The two tracks work in parallel on files that do not overlap, then converge in week 11.

| | Track A — Operations | Track B — Data |
|---|---|---|
| **Members** | 4 | 3 |
| **Modules** | M1, M3, M4, M6 | M2, M5, M7 |
| **Focus** | Forms, workflows, state machines, role-scoped screens | Telemetry ingestion, aggregation, charts, scoring |

> ⚠️ **Known imbalance.** Track B carries three modules with three people, two of which are the hardest in the project. Plan for whichever Track A member finishes first to move across around week 9. Flag it in the weekly report the moment it starts slipping — do not absorb it silently.

---

## Working agreements

### Communication

- **All supervisor contact goes through the group leader.** This is a guidelines requirement, not a preference.
- Weekly team meeting, minuted. Minutes go in `docs/progress/week-NN.md` and eventually into the report appendix.
- Anything decided in a meeting that affects code gets written down the same day — [progress/decision-log.md](../docs/progress/decision-log.md) for small calls, [decisions/](../docs/decisions/) for architectural ones.

### Code

- **One module, one owner, one branch.** Branch naming: `m4-ticketing/ticket-state-machine`.
- Pull request into `main`, reviewed by one other member before merge. No direct pushes to `main`.
- Do not edit another module's files without telling its owner. If you find a bug in someone else's module, tell them — do not silently fix it.
- Shared files (`config/atlas.php`, layouts, base components) change by agreement, because a conflict there blocks everyone.

### Documentation

- Docs change in the same commit as the code they describe.
- Every member fills in their own section of the weekly progress report. The group leader assembles it; they do not write it for you.

---

## Workload Matrix

The Workload Matrix is directly marked — "Marks will be awarded according to the quality of work and contributions from each member as stated in the Workload Matrix."

**The proposal's version needs rebuilding.** Five of seven members list "References" as a contribution, which is not a work item. Replace it with an activity × member grid:

| Activity | Member | Contribution |
|---|---|---|
| *e.g.* M4 Ticketing — schema, service, controllers, screens, DFD1, data dictionary, flowchart | *name* | 100% |

Every row should be something a marker can look at in the finished system and verify.
