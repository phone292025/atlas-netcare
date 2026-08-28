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

- ✅ [Q4](../04-open-questions.md) — **settled**: recurring weekly templates, exactly as assumed.
- ✅ [Q5](../04-open-questions.md) — **settled**: in-app bell only. **This belongs in the report's Limitations** — mobile push needs a native app or service-worker infrastructure this project does not have time for, and saying so is better than a marker noticing.

## Definition of done

- [x] Admin can define weekly templates and generate a week of shifts from them
- [x] Assistant sees today's shift on their home page and can check in and out
- [x] Inspection-round reminders fire at the configured intervals during a shift
- [x] Handover notes are captured at check-out and visible to the next shift
- [x] Notification bell shows an accurate unread count and marks read on open
- [x] M4 and M5 can raise alarms through `AlarmService` without touching M6 internals
- [x] Feature tests: generate roster, check in/out, alarm dispatch, unread count
- [ ] DFD 1, data dictionary and flowchart drafted — **outstanding, and it is a marked deliverable.**

## How it was built

**Alarms are scheduled, not written when they fire.** A row with `trigger_at` in the future is the normal case and `atlas:dispatch-alarms` sends it when due. That is the whole reason an inspection-round reminder is expressible at all — and it means a scheduled alarm is deliberately invisible and uncounted until it comes due, or the badge would be wrong all morning.

**`AlarmService` is the seam other modules use.** M4 raises SLA breaches through `TicketService::raiseSlaBreachAlarms()`, M5 raises access-point outages from its collector. Neither touches the `alarms` table, and neither knows how an alarm is stored or delivered. Both guard against repeats: **one alarm per breaching ticket ever**, and AP outages only on the transition — without those, a scheduled sweep re-alarms the same thing every minute, which is precisely how a notification bell gets muted.

**The day-of-week conversion is the trap.** Carbon numbers Sunday as `0` but `startOfWeek()` is Monday, so a naive `addDays($dayOfWeek)` puts Sunday's shift on the Monday and shifts the whole roster by six days — looking exactly like the generator not working. There is a test for Sunday specifically.

**Times are copied onto the shift, not read through the template.** Changing "Mondays 09:00" to 10:00 must not rewrite what somebody already worked last week.

**Generation is idempotent and load-balanced.** It is both scheduled and behind a button, so it will be run twice; a unique index on `(user_id, shift_date, start_time)` plus an existence check mean a second pass adds nothing. Assistants are picked by who has fewest shifts that week — not a real fairness algorithm, but it stops the same person getting every slot, which is what assigning in id order does.

**Under-filling is honest; double-booking is not.** A slot needing two assistants when only one is free produces one shift, not the same person twice.

**A missed shift is a status**, set when a scheduled shift's end time passes with no check-in — so the roster can show it and a report can count it.

**`inspections.shift_id` finally has its foreign key.** M3 created the column and left the constraint for whenever `shifts` existed; this module added it, `nullOnDelete` so deleting a shift never deletes the evidence that somebody inspected a machine during it.

## Running it

```bash
php artisan atlas:generate-shifts    # next week, from the active templates
php artisan schedule:work            # dispatches alarms every minute
```
