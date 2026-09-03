# M3 — Checklists and Inspections

**Track:** A · **Owner:** *unassigned* · **Sprint:** 2 · **Depends on:** M1, M2 · **Blocks:** M4, M7

> **Last updated:** 2026-09-04

## Purpose

Replace the paper lab-inspection log. An administrator defines a versioned checklist; an assistant completes it against a lab PC during a shift, on a phone.

## Requirements covered

FR-203, FR-204, FR-205, FR-406 · NFR-05

## Tables owned

`checklist_templates` · `checklist_items` · `inspections` · `inspection_results`

## Components

| Kind | Items |
|---|---|
| Models | `ChecklistTemplate`, `ChecklistItem`, `Inspection`, `InspectionResult` |
| Enums | `ChecklistResult` (ok/issue/na), `InspectionOutcome` (pass/pass_with_issues/fail), `ChecklistCategory` (boot/display/peripheral/network) |
| Controllers | `Admin\ChecklistTemplateController`, `Assistant\InspectionController` |
| Services | `InspectionService` — start, save results, compute overall result, hand failures to M4 · `ChecklistService` — versioning and activation |
| Policies | `InspectionPolicy` — an assistant edits only their own in-progress inspection |
| Components | `<x-checklist-item>` |

**Three names changed from the original plan, each for a reason:**

- The overall-result enum is **`InspectionOutcome`**, not `InspectionResult`. The old name collided with the Eloquent model for `inspection_results`, and PHP refuses two imports sharing a short name — every file in M3 needs both, so one would have to be aliased everywhere.
- **`ChecklistCategory`** was added. `checklist_items.category` is a bare string in the ERD, and ADR-0004 says every such column gets a backed enum; it is also the only way the inspection form's section headings get real labels and a defined order.
- **`Admin\ChecklistItemController` was not built.** Items are edited as part of the template, because position in the submitted list *is* `sort_order` — saving one item alone would have nothing to say about where it sits.

## Screens

Assistant: start round (pick location) · PC list for that location with inspected/not-inspected state · **checklist form (mobile-first, the most important screen in the system)** · inspection summary · inspection history
Admin: checklist template index/create/edit · item management with drag-to-reorder

## Key decisions

- **Templates are versioned.** Editing an active template creates a new version rather than mutating it, so historical inspections do not reference items that no longer exist. Blocked on [Q2](../02-open-questions.md).
- **Overall result is derived, not entered.** Any critical item failing means `fail`; any non-critical issue means `pass_with_issues`; otherwise `pass`.
- **A failed item offers a pre-filled ticket** with asset, location, failed item and suggested priority already populated. This is the hand-off to M4 and it is the demo's best moment — agree the interface with M4's owner early.
- **Mobile-first is a requirement, not a preference.** NFR-05 is tested at 375 px.

## Open questions

- [Q2](../02-open-questions.md) — fixed or admin-editable checklist, and is versioning wanted?

## Definition of done

- [x] Admin can build a template with ordered items, marking some critical
- [x] Assistant can complete a full inspection on a phone one-handed at 375 px
- [x] Overall result computes correctly for all three outcomes
- [~] A failed critical item offers a pre-filled ticket that M4 accepts — **the panel is built and lists each issue; the button is inert until M4 registers `assistant.tickets.create`.** M4's owner replaces one `href`, not a screen.
- [x] Editing a template creates a new version; old inspections still render correctly
- [x] Feature tests: complete inspection, derived result, template versioning
- [ ] DFD 1, data dictionary and flowchart drafted — **outstanding, and it is a marked deliverable.**

## How it was built

**The derived outcome is the module.** `InspectionService::deriveOutcome()` is three lines and every other decision defends them: an Issue on a critical item is a `fail`, any other Issue is `pass_with_issues`, otherwise `pass`. N/A is never a failure — an assistant marking "external monitor" N/A on a machine that has none has not found a fault.

`overall_result` is absent from `Inspection::$fillable`, so no form can submit a failing machine as a pass.

**Every item must be answered.** `results.*.result` only fires for keys that are *present*, so an untouched item sends nothing, has no key, and no rule runs against it. `InspectionRequest::after()` checks the submission against the template's own items. Without it a half-answered form validates and derives a Pass — a machine certified working on questions nobody answered. There is a test that fails when the check is removed.

**Answers are keyed by `(inspection_id, checklist_item_id)` at the database level** and written with `updateOrCreate`. A phone on bad campus Wi-Fi double-submits; without the unique index every answer lands twice and the same issue is counted repeatedly.

**Starting is idempotent.** Tapping "Inspect" twice resumes the existing inspection rather than opening a second record of the same visit.

**Templates version on edit, but only once used.** `ChecklistService` copies to `version + 1`, carries the items across, and moves `is_active` to the copy so an edit never leaves the system with no active checklist. An unused draft is edited in place — there is no history to protect, and versioning drafts just fills the list. The next version number comes from the highest version sharing the name, so editing v2 while v3 exists produces v4, not a second v3.

**Reordering is up/down buttons, not drag.** A drag library is a new dependency needing an ADR, and HTML5 drag-and-drop does not work by touch — the admin would be the one screen a tablet could not operate. Position in the submitted array becomes `sort_order` either way.

**`shift_id` exists on `inspections` but has no foreign key yet.** M6 owns `shifts`; its migration adds the constraint. Nothing writes the column until then.
