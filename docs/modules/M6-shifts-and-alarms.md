# M6 — Shifts, Roster and Alarms

**Depends on:** M1 · **Blocks:** nothing

> **Last updated:** 2026-10-23

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
| Services | `ShiftService` — generate, check in, check out, handover, reassign, remove, mark missed · `AlarmService` — schedule, dispatch, mark read |
| Commands | `atlas:generate-shifts`, `atlas:mark-missed-shifts`, `atlas:dispatch-alarms` |

## Screens

Assistant: my shift today (the assistant's home page) · my roster (week view) · check in / check out · handover notes form · notifications list
Admin: shift template index/create/edit · roster generator with week preview, per-shift override and removal

On a day with no shift, My shift says so in one line and gives the date, time and room of the next one. Under the shift it shows today's inspection rounds room by room (M3's `<x-room-rounds>`), with the shift's room marked.

## Key decisions

- **Templates generate dated shifts**, one week ahead, via scheduled command. Admin overrides individual shifts afterwards. Blocked on [Q4](../02-open-questions.md).
- **`AlarmService` is a shared service.** M4 calls it for SLA breaches, M5 for access points going offline or degraded. Keep its interface small and stable.
- **In-app delivery only**, with email through the `log` mailer in development. Mobile push is out of reach. See [Q5](../02-open-questions.md).
- **Alarms are scheduled, not immediate.** A row with `trigger_at` in the future is normal; the dispatcher sends it when due. This is what makes inspection-round reminders possible.
- **A missed shift is a status**, set by `atlas:mark-missed-shifts` within five minutes of a scheduled shift's end with no check-in. Until 2026-09-23 the Sunday generator set it, so Monday's no-show read "Scheduled" all week.
- **A shift can be removed only before it starts.** From then on it is attendance, and a no-show is recorded as missed rather than erased. Removing tells the assistant, cancels the unsent reminders, keeps delivered ones, and moves a pending handover note to the next shift in the same place. "Clear week" does the same for every shift in the week that has not started.
- **Generating a week skips slots that have already started.** A shift created after its start can only be a no-show, and was marked missed within minutes: a week generated on the Saturday after it produced six false "missed" shifts. Fourteen such rows were removed from the development database on 2026-09-23, with a JSON backup in `storage/app/private/backups/`.
- **Covering a shift under way sends nothing stale.** The new assignee's notice is dated now, and inspection-round reminders already past are not written.
- **Shifts are audited.** Generation, reassignment, check-in and out, missed marking and removal all write an audit row.

## Open questions

- ✅ [Q4](../02-open-questions.md) — **settled**: recurring weekly templates, exactly as assumed.
- ✅ [Q5](../02-open-questions.md) — **settled**: in-app bell only. Mobile push needs a native app or service-worker infrastructure this project does not have time for.

## Definition of done

- [x] Admin can define weekly templates and generate a week of shifts from them
- [x] Assistant sees today's shift on their home page and can check in and out
- [x] Inspection-round reminders fire at the configured intervals during a shift
- [x] Handover notes are captured at check-out and visible to the next shift
- [x] Notification bell shows an accurate unread count and marks read on open
- [x] M4 and M5 can raise alarms through `AlarmService` without touching M6 internals
- [x] Feature tests: generate roster, check in/out, alarm dispatch, unread count
- [x] **FR-408, overriding an individual assignment** — built 2026-09-02. `ShiftService::reassign()`, from the roster screen. The new assignee gets the full set of reminders, inspection rounds included, and a pending handover note moves with the shift (fixed 2026-09-23: only the start reminder used to be rebuilt). A shift can be reassigned until it ends, so a no-show can be covered, but not after (fixed 2026-09-23).
- [x] **Removing a shift, or clearing a week** — built 2026-09-23. `ShiftService::remove()` and `clearWeek()`, from the roster screen; only shifts that have not started.


## How it was built

**Alarms are scheduled, not written when they fire.** A row with `trigger_at` in the future is the normal case and `atlas:dispatch-alarms` sends it when due. That is the whole reason an inspection-round reminder is expressible at all — and it means a scheduled alarm is deliberately invisible and uncounted until it comes due, or the badge would be wrong all morning.

**`AlarmService` is the seam other modules use.** M4 raises SLA breaches through `TicketService::raiseSlaBreachAlarms()`, M5 raises access points going offline or degraded from its collector. Neither touches the `alarms` table, and neither knows how an alarm is stored or delivered. Both guard against repeats: **one alarm per breaching ticket ever**, and AP outages only on the transition — without those, a scheduled sweep re-alarms the same thing every minute, which is precisely how a notification bell gets muted.

**The day-of-week conversion is the trap.** Carbon numbers Sunday as `0` but `startOfWeek()` is Monday, so a naive `addDays($dayOfWeek)` puts Sunday's shift on the Monday and shifts the whole roster by six days — looking exactly like the generator not working. There is a test for Sunday specifically.

**Times are copied onto the shift, not read through the template.** Changing "Mondays 09:00" to 10:00 must not rewrite what somebody already worked last week.

**Generation is idempotent and load-balanced.** It is both scheduled and behind a button, so it will be run twice; a unique index on `(user_id, shift_date, start_time)` plus an existence check mean a second pass adds nothing. Assistants are picked by who has fewest shifts that week — not a real fairness algorithm, but it stops the same person getting every slot, which is what assigning in id order does.

**Under-filling is honest; double-booking is not.** A slot needing two assistants when only one is free produces one shift, not the same person twice.

**A missed shift is a status**, set when a scheduled shift's end time passes with no check-in — so the roster can show it and a report can count it.

**`inspections.shift_id` finally has its foreign key.** M3 created the column and left the constraint for whenever `shifts` existed; this module added it, `nullOnDelete` so deleting a shift never deletes the evidence that somebody inspected a machine during it.

## Calendar export

Added 2026-08-29, after M6. The alarms above only reach the bell inside the app, so an assistant who is not looking at Atlas NetCare does not know their shift starts in thirty minutes.

Rather than push messages out, the system hands the shift to the phone and the phone does the reminding. That alarm system already works with the app closed, the screen locked and no internet.

**Email was considered and rejected.** *(Updated 2026-08-29: the first of these reasons no longer holds. Accounts moved to deliverable Gmail plus-addresses so FR-102 could send a password reset link — see [Q11](../02-open-questions.md#q11). The decision stands on the reasons that follow it, not on this one.)* Every account used `@apu.test` or `@atlas.local`, both reserved domains that can never receive mail. Sending email would mean putting seven personal addresses into a public repository, or routing everything to one inbox and no longer proving the right person gets the right message. A calendar file needs no address at all.

**Two ways in, sharing one generator** in `ShiftCalendar`.

A **download button** on the shift screen gives one event, behind normal authentication, with a reminder already set for 30 minutes before. Works immediately on both platforms.

A **subscription feed** gives every upcoming shift and keeps giving them as administrators generate new weeks. Calendar applications cannot sign in, so the URL carries a 48-character token instead of relying on a session. Regenerating it invalidates every copy of the old link, which is the revoke button. The route sits outside the `auth` group deliberately and is throttled, being the only thing in the system reachable without signing in.

### Subscription is switched off by default

`ATLAS_CALENDAR_SUBSCRIPTION` is `false`, so the shift screen shows only the single-shift download.

Not because the feed is broken. It works and is tested. Because **a development server cannot host a URL a calendar client will accept**:

| Client | Accepts a feed at `http://192.168.x.x:8000` |
|---|---|
| Apple Calendar, iOS and macOS | Yes |
| Outlook, new | No. Takes only `https://`, and refuses private addresses |
| Google Calendar | No. Fetches from Google's servers, which cannot see your Wi-Fi |

Tested on 2026-08-29: clicking the subscribe link in Outlook produced **Invalid URL**. Two of the three major clients fail, and a control that fails in front of somebody is worse than one that is not there.

**Turn the flag on once the system is deployed behind HTTPS.** It then becomes the better option, because nothing is ever downloaded again.

The subscription feed, with per-user revocable tokens, is built. It needs HTTPS, and the demonstration environment is HTTP. The constraint is the environment, not the design.

### The part worth getting right

**Timezone.** A shift holds a local date and a local time, and the application runs in UTC. Emitting those values with a `Z` suffix would put a 09:00 shift into a Malaysian calendar at 17:00. Every event carries an explicit `TZID` and the file includes a `VTIMEZONE`. Malaysia has no daylight saving, so that block is a single fixed offset. A calendar showing the wrong time is worse than no calendar, and there is a test pinning it.

**Format.** CRLF line endings, folding at 75 octets, and escaping for commas and semicolons are all required by RFC 5545 rather than optional tidiness. Get any of them wrong and the phone rejects the file silently, which is indistinguishable from a broken button. Asserted in `ShiftCalendarTest`.

**Stable event IDs**, so tapping the button twice gives one calendar entry rather than two.

### Platform support, and how much of it was actually tested

Two of these were tested on a device and two were not.

| Platform | Event imports | Our 30-minute alarm | Evidence |
|---|---|---|---|
| iPhone | Yes | Yes | **Tested 2026-08-29.** Added through Safari from the app, appeared in Calendar at the right local time with "30 minutes before" set. |
| Windows, Outlook | Yes | Yes | **Tested 2026-08-29.** Outlook is the registered `.ics` handler and the strictest common parser. |
| macOS | Expected yes | Expected yes | **Not tested, no device.** macOS Calendar shares the EventKit engine with iOS, which was tested, so this is inference from a shared implementation rather than a guess. |
| Android, Google Calendar | Expected yes | **Probably replaced** | **Not tested, no device.** Google Calendar discards `VALARM` on import and on subscription, applying the user's own default notification instead. Documented behaviour, not a fault in the file. |

So on Android the assistant still gets reminded, on their own default schedule rather than the 30 minutes the file asks for. That is an acceptable outcome and an honest thing to state.

The file itself was checked against RFC 5545: every mandatory property present in `VCALENDAR`, `VEVENT` and `VALARM`, balanced nesting, and the `TZID` in use matches the `VTIMEZONE` the file declares. That is what makes the untested platforms a reasonable expectation rather than a hope.

### What it does not cover

Access points dropping and tickets going overdue are not calendar events. Those still reach the bell only. Real `@apu.edu.my` addresses would make email the obvious choice in a genuine deployment. The fake domains are a constraint of the demonstration environment, not of the design.

## Running it

```bash
php artisan atlas:generate-shifts    # next week, from the active templates
php artisan atlas:mark-missed-shifts # ended shifts nobody attended become missed
php artisan schedule:work            # dispatches alarms every minute, marks missed shifts every five
```
