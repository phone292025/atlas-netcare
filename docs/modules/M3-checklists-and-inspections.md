# M3 — Checklists and Inspections

**Track:** A · **Owner:** *unassigned* · **Sprint:** 2 · **Depends on:** M1, M2 · **Blocks:** M4, M7

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
| Enums | `ChecklistResult` (ok/issue/na), `InspectionResult` (pass/pass_with_issues/fail) |
| Controllers | `Admin\ChecklistTemplateController`, `Admin\ChecklistItemController`, `Assistant\InspectionController` |
| Services | `InspectionService` — start, save results, compute overall result, hand failures to M4 |
| Policies | `InspectionPolicy` — an assistant edits only their own in-progress inspection |
| Components | `<x-checklist-item>` |

## Screens

Assistant: start round (pick location) · PC list for that location with inspected/not-inspected state · **checklist form (mobile-first, the most important screen in the system)** · inspection summary · inspection history
Admin: checklist template index/create/edit · item management with drag-to-reorder

## Key decisions

- **Templates are versioned.** Editing an active template creates a new version rather than mutating it, so historical inspections do not reference items that no longer exist. Blocked on [Q2](../04-open-questions.md).
- **Overall result is derived, not entered.** Any critical item failing means `fail`; any non-critical issue means `pass_with_issues`; otherwise `pass`.
- **A failed item offers a pre-filled ticket** with asset, location, failed item and suggested priority already populated. This is the hand-off to M4 and it is the demo's best moment — agree the interface with M4's owner early.
- **Mobile-first is a requirement, not a preference.** NFR-05 is tested at 375 px.

## Open questions

- [Q2](../04-open-questions.md) — fixed or admin-editable checklist, and is versioning wanted?

## Definition of done

- [ ] Admin can build a template with ordered items, marking some critical
- [ ] Assistant can complete a full inspection on a phone one-handed at 375 px
- [ ] Overall result computes correctly for all three outcomes
- [ ] A failed critical item offers a pre-filled ticket that M4 accepts
- [ ] Editing a template creates a new version; old inspections still render correctly
- [ ] Feature tests: complete inspection, derived result, template versioning
- [ ] DFD 1, data dictionary and flowchart drafted
