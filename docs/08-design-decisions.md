# What the proposal did not say

> **Last updated:** 2026-09-26
>
> Every decision here was made while building, because the code needed an answer and the proposal did not have one. None of them were agreed by the team.

## Why this file exists

Your approved proposal describes what Atlas NetCare does. It does not describe it in enough detail to write code from. That is normal. Proposals are written in English and code needs numbers.

So at roughly forty points during the build, something had to be decided. I decided them. They are all working, all tested, and all invented.

That matters. Ask "why 120 milliseconds?" and the true answer right now is "an AI picked it". A better answer is "we agreed it, because that is roughly where a video call starts to stutter".

## How to use this

Sit down with the group once. Go through the list. For each one, say **keep** or **change**. Write the date and who was there at the bottom of this file.

Twenty minutes of work turns forty invented answers into forty decisions you own.

Every entry says where to change it, because most are one line.

---

## Access point health

### What counts as "congested"

**The proposal says** the dashboard will "highlight congested zones".

**It does not say** what congested means. There is no number anywhere.

**I chose:** an access point is degraded when latency goes over **120 ms**, or packet loss over **5%**, or it is more than **85% full**. Any one of those is enough. It has to hold for **3 readings in a row**.

**Why those numbers.** 120 ms is around where a video call starts stuttering. 5% loss is where a web page feels broken rather than slow. 85% is the point where an access point still works but has no headroom for a lecture ending and thirty phones reconnecting at once.

**Why three readings.** One bad sample should not turn the dashboard red and file a ticket. If a single spike could do that, people learn to ignore the dashboard within a week.

**Where to change it:** `config/atlas.php`, under `thresholds`. Five lines.

**Worth doing:** ask APU IT what latency they consider bad, or run a ping test on campus Wi-Fi during a busy hour and see what you get. Then you have a real number and a real story.

### When an access point counts as offline

**The proposal does not mention** how long silence means "down".

**I chose:** no reading for **5 minutes**.

The collector polls every minute, so five minutes is five missed polls. Short enough to notice during a lecture. Long enough that one slow poll does not raise an alarm.

**Where to change it:** `config/atlas.php`, `thresholds.offline_after_min`.

### How often the dashboard refreshes

**I chose:** every **20 seconds**, by polling rather than WebSockets.

Telemetry only arrives once a minute, so refreshing faster shows the same numbers again. A WebSocket server is infrastructure seven students would have to run, explain, and keep alive during a demo.

The page also stops polling when you switch tabs. A laptop left open overnight would otherwise make about four thousand pointless requests.

**Where to change it:** `config/atlas.php`, `poll_interval`. The reasoning is in ADR-0005.

---

## Tickets

### The states a ticket moves through

**The proposal says** a ticket runs "from the moment an IT assistant reports an issue until a network engineer closes it".

**It does not say** what happens in between.

**I chose:**

```
open -> acknowledged -> in progress -> resolved -> closed
                                          |
                                          +-> reopened -> in progress
```

**Why reopening exists.** Someone marks a fault fixed and it is not fixed. Without a reopen step, the assistant either accepts a lie or an engineer quietly edits the status back, and nobody can tell it happened. Reopening makes "this was not actually fixed" a recorded event.

**Why closed is final.** A closed ticket that can be dragged back into the queue makes every count meaningless.

**Where to change it:** one array, `TicketService::TRANSITIONS`. Reading it aloud is the specification.

### How long a ticket gets

**The proposal names three priorities** and says nothing about response times.

**I chose:** critical **4 hours**, medium **24 hours**, low **72 hours**, measured from when the ticket was opened.

**Why have an SLA at all.** Without one, "overdue" has no meaning, the alarm module has nothing to alarm about, and the reports have nothing to measure. The three numbers cost almost nothing and make three other features real.

**Where to change it:** `config/atlas.php`, `sla_hours`. Three lines. Changing them affects new tickets only, because a ticket keeps the target it was promised when raised.

### Who is allowed to close a ticket

**The proposal implies** the engineer closes it.

**I chose:** the engineer or the person who reported it. Only the reporter can reopen.

**Why.** Letting an engineer close their own work sounds risky until you notice the reporter can reopen it. That pairing is what makes it safe. The person who saw the fault gets the last word, and the engineer is not blocked waiting for a confirmation click.

**Where to change it:** `TicketPolicy::close()` and `reopen()`.

### Engineers can raise tickets too

**The proposal only describes** assistants reporting faults.

**I chose:** engineers can raise them as well.

An engineer working on one machine notices a second fault. Making them find an assistant to file it is how faults stop being recorded.

### A ticket does not need a machine

**I chose:** the asset is optional.

"No network anywhere on Level 6" is a real fault. It belongs to a place, not a box. Forcing every ticket to name a machine would make that unreportable.

### Resolution notes are required

**I chose:** you cannot resolve a ticket without writing what you did.

A fault that went away with no record teaches nobody anything, and M7 has nothing to learn from when the same machine fails again.

### Internal comments

**The proposal does not mention** comment visibility.

**I chose:** engineers and admins can mark a comment internal. The reporter never sees those.

Engineers need somewhere to say "third fault on this port this term, probably the switch" without it reading as blame to the student who reported it.

### One alarm per overdue ticket, ever

**I chose:** a breaching ticket alarms the engineers once and never again.

The check runs every minute. Without that guard it would re-alarm the same ticket 1,440 times a day, which is exactly how a notification bell gets muted.

---

## Inspections

### What the checklist actually asks

**The proposal says** assistants complete a maintenance checklist. It never lists the questions.

**I wrote all thirteen**, in `ReferenceSeeder`:

| # | Question | Category | Critical |
|---|---|---|---|
| 1 | Machine powers on and reaches the desktop | Boot | Yes |
| 2 | No unexpected error messages at start-up | Boot | |
| 3 | Fans are not running loudly or continuously | Boot | |
| 4 | Monitor displays a stable picture | Display | Yes |
| 5 | No flicker, lines or dead pixels | Display | |
| 6 | Correct resolution and no stretching | Display | |
| 7 | Keyboard responds, all keys working | Peripheral | Yes |
| 8 | Mouse responds and tracks correctly | Peripheral | Yes |
| 9 | Headphone socket produces sound | Peripheral | |
| 10 | USB ports accept a device | Peripheral | |
| 11 | Wired network connects | Network | Yes |
| 12 | Campus Wi-Fi is reachable | Network | |
| 13 | Browser reaches an external site | Network | |

**The five critical ones** are the faults that stop a student using the machine at all. Everything else is worth recording but leaves a usable PC.

**The order matters.** Boot, then display, then peripherals, then network. That is the order you actually check a machine in. An assistant should never have to walk back because the form asked for things out of sequence.

**This is the single easiest thing on this list to improve.** Ask an actual APU lab assistant what they check. Five minutes with someone who does the job beats anything I invented. Edit the list in the admin screen, no code needed.

### Pass, pass with issues, or fail

**The proposal does not say** how a whole inspection is scored.

**I chose:** an Issue on a critical item is a **fail**. Any other Issue is **pass with issues**. Everything else is a **pass**.

The assistant records what they saw. The system decides what it adds up to. There is no dropdown for the overall result, and no form can set it.

### N/A never counts against a machine

**I chose:** marking an item N/A is not a fault.

Some machines have no headphone socket. If N/A counted as a failure, every one of those machines would show as broken forever, and the assistants would start lying to the form to avoid it.

### Every item must be answered

**I chose:** you cannot submit a part-filled checklist.

This one nearly went wrong. Laravel only validates fields that are present, so an untouched item sends nothing and passes validation silently. The inspection would then score as a Pass. A machine certified working on the strength of questions nobody answered.

There is now an explicit check, and a test that fails if it is removed.

### Editing a checklist creates a new version

**The proposal does not say** whether the checklist can change.

**I chose:** admins can edit it, and editing one that has been used creates version 2 rather than changing version 1.

An inspection from six months ago points at specific questions. Editing those questions would rewrite what somebody recorded while standing at a machine. The old version stays in the database forever so old inspections still read correctly.

---

## Shifts and alarms

### Recurring patterns, not individual assignment

**The proposal says** assistants are scheduled. It does not say how.

**I chose:** weekly patterns. "Mondays 09:00 to 13:00, Level 6 IT Lab, one assistant." A command turns those into dated shifts a week ahead.

The alternative is typing every shift forever. Patterns also make the roster generator a real feature instead of a data-entry form.

### Who gets which shift

**I chose:** whoever has fewest shifts that week.

This is not a fair-scheduling algorithm and does not pretend to be. It exists because assigning in database order gives the first assistant every slot.

If a slot needs two assistants and only one is free, it creates one shift. Under-filling is honest. Booking the same person twice is not.

### Check-in opens 15 minutes early

**I chose:** you can check in from 15 minutes before your shift until it ends.

Someone arriving early should not be told to wait. A system that refuses is a system people stop using.

**Where to change it:** `config/atlas.php`, `shifts.check_in_grace_min`.

### Reminders

**I chose:** one reminder **30 minutes** before a shift, then an inspection-round reminder every **120 minutes** during it.

A four-hour shift gets one round reminder, halfway through. The reminder that would land in the last fifteen minutes is skipped, because being told to start a round as you leave is noise. Noise is how people learn to ignore notifications.

**Where to change it:** `config/atlas.php`, `shifts`.

### Handover notes go to the next person

**The proposal mentions** handover notes. It does not say who reads them.

**I chose:** the next scheduled shift in the same location gets an alarm when their shift starts, and the notes also appear on their home screen when they arrive.

Both, deliberately. An assistant who was not signed in when the alarm fired would otherwise never see it. Handover notes nobody reads are a diary.

### Missed shifts

**I chose:** a shift nobody checked into becomes **missed** once its end time passes.

A status rather than an absence of one, so the roster can show it and a report can count it.

### Alarms are in-app only

**The proposal says "alarms"** without saying how they reach anyone.

**I chose:** a notification bell with an unread count. No email, no phone push.

Mobile push needs a native app or service worker infrastructure this project does not have time for.

Phone Myat Ko has said the team is willing to add email. **As of 2026-08-29 nothing stands in the way of it:** FR-102 needed a working mailer, so one exists, and every account now has a deliverable address. Emailing alarms is a decision nobody has taken rather than infrastructure nobody has. See [Q11](02-open-questions.md#q11).


---

## Accounts and access

### Nobody can sign themselves up

**The proposal does not discuss** account creation.

**I chose:** administrators create every account. The registration page that Laravel ships with has been deleted, and a test asserts it never comes back.

This is a staff tool for a campus network. A public sign-up form on it would be a hole.

**This creates a chicken-and-egg problem**, solved by seeding the first administrator from `.env`. That account is flagged to change its password on first sign-in.

### An administrator cannot lock themselves out

**I chose:** admins cannot deactivate or demote themselves.

Not politeness. With no self-registration, the last administrator removing their own access leaves nobody able to get back in.

### Nothing is ever deleted

**The proposal does not say** what happens to old records.

**I chose:** people are deactivated, machines are retired, access points are removed, checklists are superseded. Every one keeps its history.

An inspection is evidence that a named person checked a machine on a date. If deleting a leaver erased their inspections, the record would be worthless. The same reasoning applies everywhere.

### The audit log cannot be edited

**I chose:** the audit log has no create, edit, or delete screen. It is written automatically by an observer, not by controller code.

A log somebody can edit is not evidence of anything. Writing it automatically means there is no call site to forget.

---

## Data and demonstration

### Telemetry is simulated

**The proposal assumes** APU IT will grant access to real access points.

**I chose** to build so that never mattering. Telemetry comes through a swappable driver. Simulated by default, real SNMP later, and switching is one line in `.env` plus one class. No screen, test, or migration changes.

This is ADR-0002 and it is the most defensible architectural decision in the project. Make sure whoever presents can explain it, because it directly answers "what if you never got network access?"

### The simulated data is a model, not random numbers

**I chose** to make the fake telemetry behave like a real campus.

Each access point gets a fixed personality from its ID, so the same one is consistently the busy one. There is a daily curve with a morning peak, a lunch dip, and an afternoon peak. Weekends run at about 28% of weekday load. Latency rises with the **cube** of how full an access point is, so it stays flat and healthy until it suddenly does not.

Measured on generated data: 3 to 4 clients and about 21 ms overnight, against 31 to 33 clients and about 210 ms at the 11:00 peak.

**Why go to that trouble.** If latency were random noise, M7's predictive scoring would have nothing real to find. The correlation has to actually be there.

### The six campus locations

**I invented** L6-IT-01, L6-IT-02, L5-NET-01, LIB-L3, LIB-L4 and CAF-GF, from the three zones your proposal names.

Check these against real APU room codes. It costs nothing and makes the demo more convincing.

### How long data is kept

**I chose:** 90 days of raw telemetry, 12 months of hourly summaries.

Forty access points at one reading a minute is about 1.7 million rows a month. Keeping everything forever would make the database the largest thing in the project.

---

## Still genuinely open

**Q6, who creates user accounts**, is the only question with no answer built. In practice the code assumes administrators do, because nothing else was possible. It stays marked open because the team has never confirmed that only administrators should, or whether a supervisor should be able to as well.

---

## Sign-off

When the group has been through this list, record it here.

| Date | Who was there | Decisions changed |
|---|---|---|
| *not yet done* | | |
