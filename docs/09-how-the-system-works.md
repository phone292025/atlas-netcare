# How the system works

> **Last updated:** 2026-10-28 · **Covers:** all seven modules, built and tested.
>
> A walkthrough of everything that exists, why it is built that way, and where to find it. Written to be read start to finish by somebody who did not build it.

## What Atlas NetCare is

One web portal replacing four things APU's IT team currently does on paper, over WhatsApp, and by walking round asking people.

Three kinds of user sign in and see completely different systems.

**Student IT assistants** walk lab rounds. They check machines against a checklist on their phone, report faults, and hand over to the next shift.

**Network engineers** watch Wi-Fi health and fix what the assistants report.

**Administrators** own the reference data. Accounts, rooms, equipment, checklists, and the shift roster.

Nobody sees a menu item they cannot use. The sidebar is built from one config file keyed by role, so an assistant never sees the ticket queue and an engineer never sees user management.

## Signing in

There is no sign-up page. Laravel ships with one and it has been deleted, with a test asserting it never comes back. Administrators create every account.

That leaves the obvious problem of who creates the first administrator. `php artisan atlas:create-admin` does. It generates a random password, shows it once in the terminal, and flags the account to change it at the first sign-in. Nothing in the repository holds an admin password, because anything there is public.

After sign-in, `DashboardRedirect` sends each role somewhere different. Assistants land on their shift screen, engineers on the AP dashboard, administrators on user management. Visiting `/` when signed out sends you to the login form. There is no public landing page at all, because a campus network tool has no public.

---

## A day as an assistant

This is the flow worth demonstrating, because it crosses four modules without the user noticing.

**Morning.** Swan signs in and lands on **My shift**. It shows today's shift, the location, and a check-in button. The button is disabled until fifteen minutes before the shift starts, and it says why rather than just being grey.

Below that, the handover from whoever was in that room last. Notes appear on arrival rather than only as a notification, because an assistant who was not signed in when the alarm fired would otherwise never see them.

**Checking in.** One tap. The shift moves to checked in and the time is recorded. Swan cannot check into somebody else's shift, and cannot check into next Friday's from their sofa.

**The round.** Swan taps through to Inspections, picks the lab, and sees every machine in it with the ones already checked today marked. Tapping a machine opens the checklist.

**The checklist screen matters more than any other in the system.** It is used standing up, on a phone, one-handed, thirty times an hour. Everything about it follows from that. Questions are grouped into four sections in the order you actually check a machine. Each question has three full-width buttons, OK, Issue, N/A, at 44 pixels tall. The submit bar sticks to the bottom of the screen so finishing a machine never means scrolling back past thirteen questions.

A remark box appears only when you tap Issue, so a clean answer is one tap and a faulty one is a tap plus a sentence. A rack of thirty clean machines is the normal case, and making people write a note on every OK would double the work for nothing.

**A clean machine is still thirteen taps and a submit**, one per question. Measured at 375px on 2026-09-04. There is no "mark all OK", which is the obvious thing to want on a thirty-machine round and the obvious thing to get wrong: a button that answers every question at once makes it possible to submit a full inspection without looking at the machine.

**Submitting.** The system decides the result. An Issue on a critical item is a fail. Any other Issue is a pass with issues. Everything else passes. There is no dropdown for this and no form can set it.

**Finding a fault.** The summary lists every issue with a **Raise ticket** button. That button carries the machine, the room, the fault text, and a suggested priority. The assistant types nothing they have already typed. This is the best thirty seconds of the demo.

**Reminders.** Two hours into the shift, a notification fires telling Swan a round is due. The bell in the corner shows a count.

**End of shift.** Check out, with a box for anything the next person should know. Those notes reach the next scheduled shift in that room, both as an alarm when they start and on their screen when they arrive.

---

## A day as an engineer

**The dashboard.** Four tiles across the top, online, degraded, offline, and total clients connected. Below them every access point with its latest reading, latency, packet loss, and when it was last heard from.

The page refreshes itself every twenty seconds without reloading. It shows two times, because they answer different questions: when the page last fetched, and when the newest reading was actually taken. If the collector has stopped, the second one is old and a banner says so, because the statuses on the page are then the last known state rather than a measurement. If a refresh fails it keeps the last good numbers and says so rather than blanking. A dashboard that might be stale and does not admit it is worse than no dashboard, and until 2026-09-04 this one said "updated 1s ago" over readings two days old.

It stops polling when you switch tabs.

**Drilling in.** Clicking an access point opens 24 hours of history as a chart, peak clients and mean latency on two axes. The two lines track each other visibly, which is the point.

That chart reads a summary table, not the raw readings. Twenty-four hours of raw data for one access point is 1,440 rows. The same period summarised is 24. NFR-02 asks for this under two seconds and scanning the raw table would be slow enough to notice during a demo.

**The queue.** Tickets sorted by priority, then by what is due soonest. Anything past its target shows in red, and a counter in the header says how many.

Filters for status, priority, location, engineer, and a Mine toggle.

**Working a ticket.** The buttons offered are the moves the ticket can legally make right now. An open ticket offers only Acknowledge. Acknowledging assigns it to you, because a queue full of acknowledged tickets that nobody owns is the thing this replaces.

Resolving opens a box and will not proceed without notes.

**Being told about things.** When an access point drops, engineers get both a ticket and a notification. Once, on the transition. Not every minute for six hours.

---

## A day as an administrator

Reference data, mostly. Accounts, locations, lab PCs, access points, checklists, shift patterns, the roster, and a read-only audit log.

Two behaviours worth knowing.

**Retiring a machine writes two things at once.** The status becomes retired and the row is soft-deleted, together, always. Writing one without the other gives a machine that is retired according to a report and operational according to every list on screen. Retired machines stay reachable so their inspection history survives.

**Editing a checklist that has been used creates a new version.** The old one stays forever so old inspections still render against the questions actually asked.

---

## How it is put together

Standard Laravel MVC with a thin service layer and exactly one abstraction. Seven students over eight weeks need an architecture each of them can explain under questioning, which rules out anything clever.

```
Browser
   |     Blade, Tailwind, Alpine for interactions, Chart.js for graphs
Routes
   |     grouped by role prefix
Middleware
   |     auth, then role. Nothing reaches a controller unverified
Controller
   |     validate, hand decisions to a service, return a view
Service
   |     the business rules live here
Model
   |     Eloquent. Relationships, enum casts, scopes
PostgreSQL
```

Scheduled commands enter at the service layer and skip the top three entirely. That is deliberate. A command and a browser request run the same logic. Retention and the hourly summaries are not commands at all: the database runs them as TimescaleDB jobs.

Plain admin screens, such as editing a location or a lab PC, write the model straight from the controller once the form request has validated it and the admin role check has let it through. A few small rules sit there too, such as refusing to delete a location that still has equipment in it. See the architecture document.

### The rules live in services

Eight of them, and each owns one thing.

`InspectionService` starts an inspection, saves answers, and derives the result. `ChecklistService` handles versioning and activation. `TicketService` owns the ticket state machine. `ShiftService` generates the roster and handles check-in and check-out. `AlarmService` is how anything in the system tells a person something. `TelemetryService` classifies a reading into a status. `RiskScoringService` turns measurements into a risk band. `ReportingService` builds the five report figures.

The pattern that matters: **a controller hands decisions to a service.** It validates the shape of a request, asks a service when there is something to decide, and renders the answer.

### Three separate questions, three separate places

Ticketing shows this most clearly. Moving a ticket asks three things, and they are answered in three different files.

Is the request well formed? That is `TransitionTicketRequest`.

May this person do it? That is `TicketPolicy`.

Is this move legal at all? That is `TicketService`.

Collapsing any two of them is how "an engineer closed a ticket assigned to somebody else" becomes possible.

### Hiding a button is not a rule

The ticket screen renders its buttons from the state machine, so it never offers an illegal move. The refusal still lives in the service, and a hand-written POST is rejected regardless. There is a test that posts one.

The same thinking runs through the whole system. Privilege columns are not mass-assignable. Comment visibility is a database query, not a Blade condition. Anything a form could set that it should not is simply absent from the model's fillable list.

### One vocabulary for colour

Enums return a tone, never a colour. Five of them: healthy, attention, elevated, urgent, neutral. `app/Support/Tone.php` turns a tone into Tailwind classes, one method per surface.

`elevated` was added on 2026-09-04. There were four, and the four risk bands did not fit: Medium and High both returned `attention`, so the risk chart drew two of its four bars in the same amber and the maintenance ranking gave them the same badge. Three tones are enough for a status pill and one short of enough for a four-step scale.

Nothing else in the system is allowed to write a match statement on a tone. That is how "urgent" ends up as two different reds in two different modules.

### One vocabulary for forms

Every input is an `<x-form.*>` component with its label, help text, and error message wired together. Breeze's originals were deleted and a test asserts they never return.

There are 23 shared components. `/dev/gallery` renders all of them and only exists when running locally.

---

## The modules

### M1, identity and the audit log

Two tables. Accounts, roles, and a record of every change.

Authorisation works in two layers doing different jobs. Middleware answers "may this role reach this area". Policies answer "may this user act on this record". Neither question is ever answered with an inline role check in a controller.

The audit log is written by an observer rather than by controller code, so registering a model in `AppServiceProvider` is the only step. There is no call site to forget, and writes from seeders and console commands get logged too.

### M2, locations and equipment

Three tables. Rooms, lab PCs, access points.

Asset tags and MAC addresses are unique in the database, not just in a validation rule. Duplicate asset tags are the classic inventory demo failure. MAC addresses are normalised to one format before storing, so the same device entered as `00-1b-44` and `00:1B:44` cannot slip past.

The two detail pages carry labelled empty cards where later modules plug in. Filling one means replacing an empty state, not rebuilding a page.

### M3, checklists and inspections

Four tables. Templates, questions, inspections, answers.

The derived result is the module. Everything else defends it.

Two rules the database enforces rather than trusting code with. One answer per question per inspection, because a phone on bad campus Wi-Fi double-submits and that is the normal case here, not the edge case. And a question that has been answered cannot be deleted, which is what makes versioning necessary rather than merely tidy.

Starting an inspection twice resumes the first one. Tapping a button twice on a slow connection should not open two competing records of the same visit.

### M4, ticketing

Two tables. Tickets and comments.

One ticket table serves both lab PCs and access points through a polymorphic column, and the asset is optional so a fault can belong to a room.

Ticket numbers look like `TKT-2026-00001`. Readable, quotable over the phone, and reserved inside the same transaction that creates the ticket so two simultaneous requests cannot take the same number.

### M5, telemetry and the dashboard

Two tables and the project's one real abstraction.

Everything else in the system is typed in by a user through our own screens and is real the moment it is typed. Telemetry is the only data arriving from outside, and the only data whose availability depends on a permission nobody controls. So it comes through an interface with two implementations chosen by config.

Going live is one line in `.env` plus writing one class. No screen, test, or migration moves.

An unknown driver name throws rather than falling back to the simulator. A typo silently producing invented data on a system meant to be showing real readings is the worst outcome available.

Classification lives in the service because it needs history. A single bad reading never changes anything.

### M6, shifts and alarms

Three tables. Patterns, dated shifts, alarms.

Alarms are scheduled rather than written when they fire. A row with a trigger time in the future is normal, and a command sends it when due. That is the only reason "remind me at 11:00" is expressible at all. A scheduled alarm stays invisible and uncounted until it comes due, or the badge would show tomorrow's reminders today.

`AlarmService` is how M4 and M5 talk to people. Neither touches the alarms table or knows how an alarm is delivered.

---

## Running in the background

Five commands, all prefixed `atlas:`. They do nothing unless the scheduler is running.

```bash
php artisan schedule:work
```

| Command | When | What |
|---|---|---|
| `atlas:collect-telemetry` | every minute | one reading per access point |
| `atlas:dispatch-alarms` | every minute | raises SLA breaches, then sends anything due |
| `atlas:mark-missed-shifts` | every five minutes | ended shifts nobody checked into become missed |
| `atlas:generate-shifts` | Sunday evening | next week's roster from the patterns |

Two more you run yourself.

`atlas:backfill-telemetry` invents plausible history so the charts have something to draw. Without it the 24-hour chart is empty until you have left the collector running for a day, which makes the headline feature undemonstrable.

`atlas:check-schema` audits the live database for problems the test suite cannot see. Run it after any migration.

`atlas:mail-test` sends one real email. The suite fakes the mailer, so a fully green run says nothing whatsoever about whether SMTP works — the same blind spot as SQLite hiding the MariaDB timestamp bug. On failure it reads the server's wording and says which of the three usual causes it is. Run it before a demonstration, because a Google app password can be revoked without anything in the repository changing.

**Aggregation and roster generation are both safe to run twice.** They are scheduled and there are buttons for them, so they will be run twice. Aggregation corrects rows rather than doubling every point on a chart. Generation adds nothing rather than rostering everybody again.

---

## Testing

The current count is in [progress/build-log.md](progress/build-log.md), which is the one place that states it.

The suite runs twice. Locally it uses SQLite in memory, which is fast and has caused three bugs: SQLite and MariaDB disagree about `FIELD()`, about date truncation, and about whether the first timestamp column in a table quietly rewrites itself on every update. That last one was destroying the SLA baseline on every ticket transition, and a fully green suite never noticed.

From 2026-09-04, CI also ran the whole suite against MariaDB 10.4 on every push, using `phpunit.mariadb.xml`. That closed the gap the three bugs came through, and it found a fourth on its first run: a telemetry test that claimed to prove latency rises with congestion was actually comparing two access points' ID-derived personalities, and passed on SQLite only because in-memory SQLite hands out the same two IDs every time.

Since 2026-10-22 that second run is PostgreSQL with TimescaleDB, the database Atlas runs on now, using `phpunit.pgsql.xml`. Its first run found three more defects: date buckets written for MariaDB, `LIKE` on `json` columns, and email addresses that differed only in case.

That is what `atlas:check-schema` exists for. Since 2026-08-30 it also checks the DATA: seven invariants looking for timelines that cannot have happened, because fixing the cause of a corruption does nothing about the rows it already damaged. One ticket was carrying a `closed_at` eight hours before its own `opened_at`, and a green suite, a clean schema audit and a plausible-looking screen all missed it.

Two habits came out of building this.

**A route whose tests only assert failures has never been rendered.** M3 shipped three 500 errors onto routes that all had tests, every one asserting a 403 or a redirect. Nothing had ever fetched those pages successfully. Every screen now has a test that loads it, in its empty state as well as its populated one, because empty is what every screen looks like on the day it ships.

**A regression test is checked against the bug.** Write the fix, write the test, then put the bug back and watch the test fail. A test that passes against broken code is worse than no test, because it makes you confident. This has caught two tests that looked fine and proved nothing.

---

## What is not built

**A demo dataset with anything in the top two risk bands.** Every seeded asset scores Low or Medium, because the demo data holds no old machines and no degrading links. The scoring is right; there is nothing dramatic to score. A maintenance ranking with no Critical row does not show what the screen is for, so this is worth fixing before the demonstration.

**Real SNMP telemetry.** The driver is a deliberate stub that throws with instructions.

**Push notifications.** Alarms reach the notification bell inside the application and nothing else. Shift reminders are the exception and get out to the phone through its own calendar, which alarms with the app closed. Unplanned events, an access point dropping, still reach the bell only.

Email is no longer on this list. FR-102 sends a password reset link through Gmail SMTP and it was confirmed arriving on a phone on 2026-08-29. Alarms could now be emailed too, since the account and the configuration exist — that is a choice nobody has made rather than a thing that cannot be done.

---

## Where to look

| Question | File |
|---|---|
| Why is it built this way? | `docs/decisions/` |
| What did we assume rather than decide? | `docs/08-design-decisions.md` |
| What is the schema? | `docs/04-database.md` |
| What does this module do? | `docs/modules/` |
| What changed and why? | `docs/progress/decision-log.md` |
| What is the current state? | `docs/progress/build-log.md` |
| What does this component look like? | `/dev/gallery` while running locally |
