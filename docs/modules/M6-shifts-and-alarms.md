# M6 — Shifts, Roster and Alarms

**Track:** A · **Owner:** *unassigned* · **Sprint:** 3 · **Depends on:** M1 · **Blocks:** nothing

## Purpose

Schedule student IT assistants, remind them of inspection rounds, and track handover between shifts. Also the delivery mechanism for every other module's alerts.

## Requirements covered

FR-201, FR-202, FR-210, FR-211, FR-212, FR-407, FR-408, FR-506

## Tables owned

`shift_templates` · `shifts` · `alarms`

## Components

| Kind | Items |
|---|---|
| Models | `ShiftTemplate`, `Shift`, `Alarm` |
| Enums | `ShiftStatus`, `AlarmType` |
| Controllers | `Admin\ShiftTemplateController`, `Admin\RosterController`, `Assistant\ShiftController`, `NotificationController` |
| Services | `ShiftService` — generate, check in, check out, handover · `AlarmService` — schedule, dispatch, mark read |
| Commands | `atlas:generate-shifts`, `atlas:dispatch-alarms` |

## Screens

Assistant: my shift today (the assistant's home page) · my roster (week view) · check in / check out · handover notes form · notifications list
Admin: shift template index/create/edit · roster generator with week preview and per-shift override

## Key decisions

- **Templates generate dated shifts**, one week ahead, via scheduled command. Admin overrides individual shifts afterwards. Blocked on [Q4](../04-open-questions.md).
- **`AlarmService` is a shared service.** M4 calls it for SLA breaches, M5 for AP-offline alerts. Publish a small, stable interface and tell the other owners.
- **In-app delivery only**, with email through the `log` mailer in development. Mobile push is out of reach. Say so in the report's Limitations. See [Q5](../04-open-questions.md).
- **Alarms are scheduled, not immediate.** A row with `trigger_at` in the future is normal; the dispatcher sends it when due. This is what makes inspection-round reminders possible.
- **A missed shift is a status**, set by the generator when a scheduled shift's end time passes with no check-in.

## Open questions

- [Q4](../04-open-questions.md) — recurring templates vs individual assignment
- [Q5](../04-open-questions.md) — alarm delivery channel

## Definition of done

- [ ] Admin can define weekly templates and generate a week of shifts from them
- [ ] Assistant sees today's shift on their home page and can check in and out
- [ ] Inspection-round reminders fire at the configured intervals during a shift
- [ ] Handover notes are captured at check-out and visible to the next shift
- [ ] Notification bell shows an accurate unread count and marks read on open
- [ ] M4 and M5 can raise alarms through `AlarmService` without touching M6 internals
- [ ] Feature tests: generate roster, check in/out, alarm dispatch, unread count
- [ ] DFD 1, data dictionary and flowchart drafted
