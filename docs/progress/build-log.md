# Build Log

> **Last updated:** 2026-09-23
>
> **Read this first in any new development session.** It is the handover between sessions: what exists, what is half-done, and what is next. Conversation history does not survive; this file does.

---

## Where the build is right now

| | |
|---|---|
| **Phase** | The code is finished. What remains is the report. |
| **Last session** | 2026-09-23 (review, then live testing in the browser) |
| **Modules done** | **7 of 7** — M1 identity · M2 assets · M3 inspections · M4 ticketing · M5 telemetry · M6 shifts and alarms · M7 predictive risk and reports |
| **Tests** | **961 passing (1 skipped)** on SQLite; **962 passing, none skipped** on MariaDB. Zero deprecations. The assertion count is deliberately not quoted: it moves between runs, because `isOutage()` keys on a 20-minute window, so which access points the simulator makes unreachable changes with the clock, and the loops that skip them assert a different number of times. The test count is stable. Stated here and, as a target to check an install against, in `11-installation-guide.md`. **Nowhere else** — it was in five documents and three disagreed. |
| **Mail** | **Working.** Gmail SMTP, app password in `.env`, confirmed arriving on a phone. Check it with `php artisan atlas:mail-test` before any demonstration. |
| **Environment** | Laravel 12.68.0 + Breeze · MariaDB 10.4.32 via the `mariadb` driver · schema migrated and seeded |
| **Next action** | **The report, not the code.** All seven modules are built, the four whole-system diagrams are drawn (`docs/12-system-diagrams.md`), and the code items that were outstanding on 2026-08-30 are done. What is left needs people rather than commits: each module owes a DFD 1, data dictionary and flowchart from an owner **nobody has assigned**, and four UAT sessions need real participants. |
| **CI** | GitHub Actions runs the suite on SQLite **and** MariaDB 10.4, plus Pint, on every push. `.github/workflows/tests.yml`. |
| **To see live data** | `php artisan atlas:backfill-telemetry --days=14` then `php artisan schedule:work`. Without the scheduler the dashboard silently goes stale. |

---

## Live testing — 2026-09-23

The review branch run on localhost with the scheduler going, signed in as the administrator, every screen opened and the background jobs watched. Six defects the suite had not caught, each now with a test checked by breaking the fix.

- **Every shift reminder fired eight hours late.** `AlarmService::raise()` stored the campus-time Carbon from `Shift::startsAt()` as its wall-clock digits, so an 08:30 reminder was read back as 08:30 UTC and sent at 16:30. `trigger_at` is now converted to UTC before it is saved. The twelve reminders already written for the week of 28 Sep were moved back eight hours in the development database.
- **The degraded alert fired 19 times per engineer in five minutes** at peak, as access points near a threshold flapped. It now alerts only from Online, at most once an hour per access point (`degraded_alert_cooldown_min`).
- **Every status flap wrote an audit row**, about six a minute, credited to whoever pressed "Generate sample". The collector saves the status quietly; the reading row already records it.
- **A shift nobody attended said "Scheduled" until Sunday**, and the roster still offered to reassign it. `atlas:mark-missed-shifts` now runs every five minutes, and a shift that has ended cannot be reassigned.
- **A shift could not be taken off the roster.** Administrators can now remove a shift, or clear a week, until it starts. Past shifts stay: they are the attendance record.
- **Reassigning a shift was not audited.** Shifts are now an audited model.

One regression from the review itself, caught while adding the above: `CampusClock::dateFrom()` was fed `$request->query('week')` on the roster, but "Generate this week" posts the week in the form body, so generating while looking at next week filled this week. Its test counted the shifts and not their week; it now checks the week.

**Then the assistant role**, signed in as Swan: a ticket, an inspection with a failed critical item, a ticket from it, check-in and check-out on a throwaway shift, the handover reaching the next assistant, and deactivation signing the open session out. All worked. Six more things were fixed on the way:

- **Generating a week under way created shifts in the past**, which were marked missed within minutes and sent their reminders at once. Generation now skips any slot that has started. Fourteen false "missed" shifts, and their 24 reminders, were removed from the development database, with a JSON backup in `storage/app/private/backups/`.
- **Covering a shift under way** dated the new assignee's notice at the old reminder time and sent every past round reminder at once.
- **A critical ticket reached nobody's bell** until it went overdue. It now alerts every engineer on arrival; the threshold is `ATLAS_NEW_TICKET_ALERT_FROM`.
- **The same failed checklist item could be ticketed twice**, and the development data held such a pair (TKT-2026-00049 and 00050). A new nullable `tickets.inspection_result_id` records the answer; a second ticket is refused and the inspection shows the first. The pre-filled title now reads "Failed check: …", not the check's own wording, which said the opposite of the fault.
- **An engineer opening a ticket notification landed on the assistant's read-only page.** The existing test asserted that, so it was updated rather than just passing.
- **The audit screen showed moments in UTC**, so an 11:16 check-in read 03:16.

**Verified.** 958 passing and 1 skipped on SQLite, 959 on MariaDB, Pint clean; every new test was checked by breaking its fix.

**Then the engineer role**, signed in as Yar Zar Min: the queue, an internal note, acknowledge (which assigns), start work, resolve refused without notes and accepted with them, close, and the reporter's view checked at each step (resolution notes shown, internal notes not). A critical ticket raised as Swan rang both engineers' bells, and the notification opened the engineer's ticket screen. The overdue alarm fired a minute after the deadline. The maintenance ranking's preventive ticket pre-fills correctly. Two more fixes:

- **Reassigning a ticket told nobody.** The new assignee now gets a `ticket_assigned` alarm; taking one yourself does not.
- **Every status change read "added an internal note"** in the timeline. They now read "updated the ticket".

The simulation controls were exercised by hand: forcing an outage on all 29 access points raised 26 tickets (the other three already had one open) and alerted both engineers once per access point, as designed.

**Verified.** 961 passing and 1 skipped on SQLite, 962 on MariaDB, Pint clean; the new tests were checked by breaking their fixes. All three roles have now been clicked through.

---

## Review, fixes and cleanup — 2026-09-23

A scored end-to-end review, then fixes for everything it found, each with a test that was checked by breaking the fix. Every decision is in the [decision log](decision-log.md) under 2026-09-23.

**Seven defects, all reproduced before they were fixed.**

- **A deactivated account kept working through any route without `role:` middleware.** With a live session it could still raise tickets, read notifications and poll `api/dashboard`, and a "remember me" cookie never expired. `EnsureAccountIsActive` on the `web` group now signs it out. The calendar feed refuses its token too.
- **Every telemetry round wrote one audit row per access point** (`last_seen_at`): about 42,000 a day with the scheduler running. Models now declare their system-written caches in `AUDIT_EXCEPT`. The 166 heartbeat rows already in the development database were left alone.
- **Two shift tests failed every night from 00:00 to 08:00 campus time**, because their fixtures used UTC `today()`. CI would have gone red on any push between 16:00 and 24:00 UTC. A third clock-dependent test, `the backfill never overrules a newer live reading`, failed in the first minute of every hour; it now freezes the clock at half past.
- **Reassigning a shift dropped its inspection-round reminders** and a pending handover note.
- **The forgot-password form told strangers which addresses had accounts.**
- **FR-305 was half built:** a degraded access point never alerted anybody.
- **A hand-edited `?week=`, `?from=` or `?to=` was a 500** on three screens.

**Smaller fixes.** A view queried the database (`shift-templates/edit`); the layout's notification count moved into the `AppLayout` component; ticket transitions re-read the row under a lock; the SLA sweep uses `NOT EXISTS` instead of an unbounded `NOT IN` list; a test was appending fake reset links to the real `storage/logs/laravel.log`, so `atlas:reset-link` could print a test's link instead of yours.

**Cleanup.** `axios` and `bootstrap.js` removed (unused); the three polling screens share helpers in `app.js`; `AuditReviewFixesTest` split into the module test files its tests belong to, with its one tautological test replaced by a real check; six unused methods and scopes removed; the code map stopped listing line counts; about 840 comment lines of retold history removed from `app/`, with a token comparison proving no code changed.

**Verified.** 921 passing and 1 skipped on SQLite, 922 on MariaDB, Pint clean, zero deprecations. The polling helpers were exercised in a browser, and the three screens' inline scripts were rendered and syntax-checked with Node. The signed-in screens were not clicked through by hand.

**Still open, and a decision for the project lead rather than a fix:** whether `atlas:reset-link`, `atlas:renumber-tickets` and the code map earn their keep, and what to do with the stale `live-telemetry`, `telemetry-fixes` and `ui-redesign` branches.

---

## Full-project audit — 2026-09-08

A second review, run against the whole codebase rather than a module at a time, and deliberately from angles the previous one did not use. Three defects, all found by asking a question no existing test asked.

- **`assistant.inspections.show` returned a 500 on an unfinished inspection.** The controller carried a comment saying it redirected the assistant back to the form; no redirect had ever been written, so the summary rendered anyway — and it opens with a status badge on `overall_result`, which is null until submission. Found by walking every GET route as all three roles. The route already had tests; every one of them handed it a *completed* inspection.
- **Eight domain timestamp columns were still `timestamp`.** The 2026-08-29 migration fixed the three that had already been damaged and stopped. The remaining eight were safe only because MariaDB's implicit `ON UPDATE` lands on NOT NULL columns and all eight happened to be nullable — a property of the null flag, not of the design. Now `datetime`, values verified identical row by row across the conversion.
- **`atlas:backfill-telemetry` left every access point's cached status disagreeing with its own history.** The AP health table reads status from the access point and latency from the newest reading, so AP-18 showed *Online, no latency, 100% packet loss*. One update per access point at the end of the command, which is forty against 350,000 inserted rows.

**Three test files added, each covering a join the per-module tests cannot see.** `RoleAccessMatrixTest` (42 screens x 3 roles, including the `show` and `edit` screens the empty-database sweep cannot reach). `CrossRoleTicketWorkflowTest` (one fault from the assistant who raised it to the engineer who resolved it). `ListScreensDoNotNPlusOneTest` (query counts against 3 rows and then 30). All three were checked by breaking what they guard.

**What was checked and found correct**, so nobody re-audits it: every report figure reconciles against raw counts (risk 118 assets, tickets 48, inspections 20 passed + 9 failed = 29); no N+1 on any list screen; privilege escalation through the profile form is blocked and now has a test; deactivation holds against an existing session; the ticket state machine, its illegal transitions and its assignment guards all behave as documented.

**Still open.** The engineer and administrator screens were verified through the HTTP layer, not a browser — see the session note below.

## End-to-end review — 2026-09-04

The system was driven rather than read: every screen with full demo volume on MariaDB, every workflow through the HTTP layer as each role, and the assistant's round in a browser at desktop and 375px. Seven scenarios, 158 assertions, every screen under 16 queries. What that found, and what was done:

- **The collector, after any pause, marked every access point offline and raised a ticket for each.** `classify()` consulted `last_seen_at` on readings that had just arrived. Reproduced on a fresh seed; the live database was primed for it. Fixed in `TelemetryService`, with the regression test named for the demo-day case. **This is the one that would have happened on the morning of the demonstration**, the moment somebody started `schedule:work`.
- **Auto-raised tickets about demo assets were real rows**, so the purge refused after one collection round. They carry `is_demo` now.
- **"Updated 1s ago" over two-day-old readings.** Both status screens now state the reading age and warn when the collector has stopped.
- **The live database numbered tickets from 96 thousand.** `atlas:renumber-tickets`, run here after a backup: 48 tickets renumbered.
- **Stock error pages**, replaced. "Coming up" repeating today, fixed. Machine picker grouped by location. My Tickets stacks on a phone.

**Not code, still true.** The scheduler is not running on this machine and has not since 2 September; the notification bell is empty because nothing has dispatched. Start `php artisan schedule:work` before the demonstration. With the collector fix it is now safe to do so on stale data.

## M7 built — 2026-08-30

The last module, and the one that makes this a predictive maintenance system rather than a monitoring one. Everything else in Atlas NetCare reports what has already happened; M7 ranks what is about to.

**The model, in a sentence you can say out loud.** Each factor is a measurement divided by a ceiling from `config('atlas.risk_inputs')`, giving 0 to 1, clamped so nothing exceeds its share, multiplied by a weight from `config('atlas.risk_weights')`, summed and scaled to 0-100. No hidden step and no learned parameter. That is the whole of it, and being able to say that is why ADR-0007 chose weights over a trained model.

**Two factor sets.** A lab PC leaves history — age, faults, critical inspection failures, inspection lag. An access point leaves telemetry, so it is scored on trends over two weeks. Config was restructured from one flat list into one set per type.

**What was harder than expected.** Deciding what an unmeasurable factor means. Counting a missing purchase date as zero risk quietly punishes a machine for a blank field, and it is invisible: the score looks plausible either way. Dropping the factor and renormalising is right, and it needed the stored breakdown rescaled too so the parts still sum to the total on screen.

**What the tests caught.** Nothing, at first — all 14 scoring tests passed on the first run, which is suspicious in a piece of arithmetic this fiddly. So the clamp and the renormalising were each removed to confirm the tests actually fail without them. They do. **Do that for any test whose subject is a calculation.**

**What nearly shipped wrong.** Adding Reports to the engineer sidebar broke an invariant nobody had violated before — every navigation entry must be namespaced under its own role — and `AtlasConfigTest` caught it. Separately, two `AppShellTest` tests were resting on `admin.reports.index` being unbuilt, so finishing the module broke them. Both are worth remembering: a test that depends on work being incomplete passes right up until the work is done.

**Verified against MariaDB, not only SQLite.** Every report aggregation was run against the live database as well as the suite, because the suite has now hidden three MariaDB-specific defects and reports are the most SQL-heavy code in the project.

**Then three bugs appeared the moment somebody looked at the screen.** A chart with no options crashed Chart.js and drew a blank box, latent in `<x-chart>` since M5. The ticket matrix showed a grid of zeroes under a heading reading "3 tickets", because Eloquent casts the columns to enums and the comparison was against strings. And a ticket resolved before it was opened, corrupted by the timestamp bug fixed the day before, was dropped silently so the report claimed nothing had been resolved. **Every test asserted headings; none asserted numbers.** The suite was green throughout.

**Still open.** FR-408, and demo data that produces something in the top two risk bands — every seeded asset currently scores Low or Medium, and a maintenance ranking with no Critical row does not demonstrate what the screen is for.

---

## Password reset made real — 2026-08-29

FR-102 was **deleted** that morning for lying to the user, then restored the same day once the proposal was checked. It lists "recover password" for all three roles. **Being broken is grounds for fixing a requirement, not for deleting one, and the requirements file should have been read before anything was removed.**

The flow had never been broken. Delivery had, twice over. `MAIL_MAILER=log` wrote the message into `storage/logs/laravel.log` rather than sending it, and every account used `@apu.test` or `@atlas.local`, reserved domains that can never receive mail, so no mailer configuration could have helped.

**Both fixed.** Accounts moved to Gmail plus-addresses — `+admin`, `+phone`, `+yar`, `+swan`, `+nyan` on one account, four deliverable addresses in one inbox somebody already owns, driven by a single `.env` line so nothing real reaches the repository. `MAIL_PASSWORD` is a Google app password, pasted by hand.

Confirmed working end to end: the command sends, the screen sends, the email arrives, the link opens the form with the address prefilled.

Four things came out of using it that no test would have found:

**`config('atlas.mail_configured')`.** Mail silently does nothing in two ways — mailer `log`, or `smtp` with no password — and Laravel cannot tell either from a working mailer. The screen names which, behind `app.debug`.

**A refused SMTP login was a 500.** `sendResetLink()` sends inline, so Gmail's `535` surfaced as a stack trace on the one screen reachable without signing in.

**The reset link was behind `guest`.** Tapping it from Gmail on a phone that was already signed in opened the profile screen with no explanation. Nobody controls which browser opens a link from their inbox. The token is the authorisation; a session alongside it now changes only the wording.

**A reset did not end existing sessions.** Breeze rotates `remember_token` and stops, so somebody who had taken over an account stayed signed in while the owner reset the password believing otherwise. That is the entire point of the feature.

**Known limit, and it will bite at a demonstration.** The link is built from the address the form was loaded on, so a reset requested at `localhost` produces a link that is dead on a phone. Do the whole flow on the phone, or set `APP_URL`.

---

## Calendar export — built 2026-08-29

Decided with Phone Myat Ko and built the same day. See [../modules/M6-shifts-and-alarms.md](../modules/M6-shifts-and-alarms.md) for how it works.

**The problem.** M6's alarms only reach the notification bell inside the app. An assistant who is not looking at Atlas NetCare does not know their shift starts in 30 minutes, which is most of the point of having shift alarms at all.

**Rejected: email.** *(Half of this reasoning expired on 2026-08-29, when accounts moved to deliverable Gmail plus-addresses for FR-102. The calendar decision does not depend on it — a calendar file still needs no address, no SMTP account and no service worker, and still alarms with the app closed. See [Q11](../02-open-questions.md#q11).)* Every account in the system used `@apu.test` or `@atlas.local`. Both are reserved domains that can never receive mail, chosen deliberately in M1 so a stray notification could never reach a real person.
 Sending email would mean putting seven teammates' personal addresses into a public repository, or routing everything to one inbox and no longer proving the right person gets the right message.

**Agreed instead: hand the shift to the phone's own calendar.** iOS and Android already have reminder systems that work with the app closed, the phone locked, and no internet. Nothing we build will beat them. A calendar file needs no email address, no SMTP account, no bot, and no service worker.

**Two parts, sharing one generator:**

1. **Add to my calendar** button on the shift screen. Downloads one event with a reminder already set for 30 minutes before. Works immediately on both platforms.
2. **Subscribe to my roster.** One link, and every shift appears in the assistant's calendar and keeps appearing as administrators generate new weeks. Needs a per-person secret token in the URL, revocable, because calendar apps cannot sign in. Google refreshes subscriptions slowly, sometimes up to a day, so part 1 stays useful for "I need this on my phone now".

**What it does not solve.** Access points dropping and tickets going overdue are not calendar events. Those still reach the bell only. Worth stating plainly in the report's Limitations, along with the note that real APU addresses would make email the obvious choice in a genuine deployment. The fake domains are a constraint of the demonstration environment, not of the design.

**Built.** 21 tests in `ShiftCalendarTest`, covering the file format as well as the behaviour, because a malformed calendar is rejected silently by the phone and looks exactly like a broken button.

---

## Module progress — the live tracker

This is the table to read. Seven modules, one owner each; the split exists because Section F requires an **individual** DFD 1, data dictionary and flowchart per member.

A module is not done until every box in the definition of done in [../modules/README.md](../modules/README.md) is ticked — migrations, model, service, thin controllers, screens from the shared components, a feature test for the happy path *and* an authorisation failure, and the three report diagrams drafted.

| Module | Track | Owner | Sprint | Status |
|---|---|---|---|---|
| [M1 — Identity, roles, audit log](../modules/M1-identity-and-audit.md) | A | *unassigned* | 1 | ✅ **Done 2026-08-28** |
| [M2 — Locations and asset inventory](../modules/M2-locations-and-assets.md) | B | *unassigned* | 1 | ✅ **Done 2026-08-28** |
| [M3 — Checklists and inspections](../modules/M3-checklists-and-inspections.md) | A | *unassigned* | 2 | ✅ **Done 2026-08-28** |
| [M4 — Workspace ticketing](../modules/M4-ticketing.md) | A | *unassigned* | 3 | ✅ **Done 2026-08-28** |
| [M5 — Telemetry and AP dashboard](../modules/M5-telemetry-and-dashboard.md) | B | *unassigned* | 2–3 | ✅ **Done 2026-08-28** |
| [M6 — Shifts, roster and alarms](../modules/M6-shifts-and-alarms.md) | A | *unassigned* | 3 | ✅ **Done 2026-08-29** |
| [M7 — Predictive risk and reports](../modules/M7-predictive-and-reports.md) | B | *unassigned* | 4 | ✅ **Built 2026-08-30.** Scoring, ranking, preventive tickets and five reports. Diagrams outstanding. |

**Seven owners are still unassigned.** Nothing technical is blocked by that, but the individual report deliverables are — each person documents the module they personally built, and nobody currently owns one.

### What M1 delivered

| | |
|---|---|
| Tables | `audit_logs` (+ the `users` columns from Stage 4) |
| Screens | User index with filters and sorting · create · edit · show with history · audit log |
| Routes | `admin.users.*`, `admin.audit.index` — live in the sidebar |
| Requirements | FR-101…FR-106, FR-401, FR-402, FR-409, FR-509, NFR-03, NFR-04, NFR-06 |

### What M2 delivered

| | |
|---|---|
| Tables | `locations`, `lab_pcs`, `access_points` — already migrated in Stage 4, no schema change |
| Screens | Locations index/create/edit · Lab PC index/create/edit/**show** · Access point index/create/edit/**show** |
| Routes | `admin.locations.*`, `admin.lab-pcs.*`, `admin.aps.*` — live in the sidebar |
| Requirements | FR-403, FR-404, FR-405 · NFR-08 |
| Tests | 40 feature tests across three classes |

**The two show pages are the ones later modules plug into.** Each carries labelled, empty cards where M4 (tickets), M5 (telemetry) and M7 (risk) hang their content — see `resources/views/admin/lab-pcs/show.blade.php`. Filling one is a matter of replacing an `<x-empty-state>`, not restructuring a page.

**Retirement is two columns written together.** `destroy()` sets `status = retired` *and* soft-deletes; `restore()` undoes both. `retired` is missing from the form's status dropdown and rejected by `LabPcRequest` on purpose — it is what the Retire button does, not a state you can pick. Setting one column without the other gives a machine that is retired in a report and operational on screen.

Access points have no equivalent status case: `ApStatus::Maintenance` means "temporarily off", not "gone", so for an AP removal is the soft delete alone.

### What M3 delivered

| | |
|---|---|
| Tables | `checklist_templates`, `checklist_items`, `inspections`, `inspection_results` — four new migrations |
| Screens | Assistant: rounds index · machines in a location · **the checklist form** · summary. Admin: template index/create/edit with the item builder |
| Routes | `assistant.inspections.*`, `admin.checklists.*` — live in the sidebar |
| Requirements | FR-203, FR-204, FR-205, FR-406 · NFR-05 |
| Tests | 42 feature tests across two classes |
| Settled | **Q2** — admin-editable templates with versioning, exactly as assumed |

**The derived outcome is the module.** An Issue on a critical item is a `fail`, any other Issue is `pass_with_issues`, otherwise `pass`; N/A is never a failure. `overall_result` is not fillable, so no form can submit a failing machine as a pass.

**Editing a used template creates a new version** rather than mutating it, so a six-month-old inspection still renders against the questions it was actually asked. An unused draft is edited in place.

**Three bugs the tests did not catch, all found by rendering the pages.** The checklist form 500'd on a Collection subscript for every inspection with no answers yet; the assistant landing page 500'd on `having()` against a `withCount` alias; `LabPc::inspections()` did not exist. All three routes had tests — every one of which asserted a 403 or a redirect, so none had ever rendered the page. **A route whose tests only assert failures has never been rendered.** There are now tests that render each of them.

### What M4 delivered

| | |
|---|---|
| Tables | `tickets`, `ticket_comments` — two new migrations |
| Screens | Engineer: queue with filters · ticket detail with transitions and comments. Assistant: my tickets · raise ticket · ticket detail |
| Routes | `engineer.tickets.*`, `assistant.tickets.*`, `tickets.transition`, `tickets.comments.store` |
| Requirements | FR-206, FR-207, FR-208, FR-306, FR-307, FR-308 · part of FR-503 |
| Tests | 51 feature tests across two classes |
| Settled | **Q3** — adopted as proposed, *not* ratified by the team. See below. |

**Q3 was adopted, not agreed.** M4 could not be built without a lifecycle and the team has not met on it, so the documented proposal was implemented exactly as written. It is still cheap to overturn: the machine is one array in `TicketService::TRANSITIONS`, the targets are three lines in `config/atlas.php`. **Anyone who wants a different lifecycle should say so rather than assume it is now fixed.**

**Hiding a button is not a rule.** Actions render from `nextStates()`, so the UI never offers an illegal move — but the refusal is in the service, and a hand-written POST is rejected too. Three questions, three places: the request validates shape, the Policy decides who, the service decides whether the move is legal at all.

**Internal comments are filtered in the query.** `TicketComment::scopeVisibleTo()` is the only place visibility is decided; a Blade `@if` would be one edit away from showing an assistant what the engineers said about their report.

**The M3 hand-off is live.** A failed checklist item pre-fills the machine, location, fault text and a suggested priority, and the ticket keeps a link back to the inspection that found it (FR-207). M3 built the panel behind a `Route::has()` guard, so wiring it up was deleting the guard.

### What M5 delivered

| | |
|---|---|
| Tables | `telemetry_readings`, `telemetry_hourly` — two new migrations |
| Screens | Engineer: **live AP dashboard** · AP detail with 24h chart · traffic and congestion logs. Assistant: read-only AP status |
| Commands | `atlas:collect-telemetry` (every minute) · `atlas:aggregate-telemetry` (hourly) · `atlas:prune-telemetry` (weekly) · `atlas:backfill-telemetry` (by hand) |
| Routes | `engineer.dashboard`, `engineer.aps.*`, `engineer.traffic.index`, `assistant.aps.index`, `api.dashboard` |
| Requirements | FR-209, FR-301…FR-305, FR-503 · NFR-02, NFR-07 |
| Tests | 37 across two classes |
| Settled | **Q1**, **Q9**, **Q10** — all three built as assumed |

**The driver swap is the design, and it is tested.** `TelemetrySource` has two implementations chosen by `config('atlas.telemetry_driver')`; going live is an `.env` change and one class. An unknown driver name **throws** rather than falling back — a typo silently producing invented data on a system meant to show real readings is the worst outcome available.

**The simulator is a model, not `rand()`.** Fixed per-AP personalities, a two-humped daily curve, weekends at 28%, and latency rising with the cube of saturation. Generated data measures 3–4 clients / ~21 ms overnight against 31–33 clients / ~210 ms at the 11:00 peak, and the correlation is visible on the chart. ADR-0002 warns a sharp marker will look for exactly this, so there are tests asserting both the curve and the correlation.

**Classification lives in the service** because it needs history. `consecutive_breach` stops one noisy sample flipping a colour or auto-raising a ticket, and `Maintenance` survives untouched — it is an administrative decision, not a measurement.

**An access point going offline raises exactly one ticket**, guarded twice: only on the transition, and only if nothing is already open for that asset.

**Run `atlas:backfill-telemetry` before demonstrating.** A 24-hour chart that is empty until you have left the collector running for a day is not demonstrable.

### What M6 delivered

| | |
|---|---|
| Tables | `shift_templates`, `shifts`, `alarms` — plus the foreign key M3 left waiting on `inspections.shift_id` |
| Screens | Assistant: **my shift today** (their home screen) · my roster · notifications. Admin: shift templates · roster with week view and generator |
| Commands | `atlas:generate-shifts` (weekly) · `atlas:dispatch-alarms` (every minute) |
| Routes | `assistant.shifts.*`, `admin.shift-templates.*`, `admin.roster.*`, `notifications.*` |
| Requirements | FR-201, FR-202, FR-210, FR-211, FR-212, FR-407, FR-408, FR-506 |
| Tests | 47 across two classes |
| Settled | **Q4** and **Q5**, both as assumed |

**The notification bell is live.** It has been an inert icon in the layout since Sprint 0 with the title "Notifications arrive with M6"; it now carries a real unread count for all three roles. `assistant.shifts.today` is likewise now registered, so `UserRole::homeRoute()` finally lands assistants on their shift screen instead of falling back to the profile page.

**`AlarmService` is the seam.** M4 raises SLA breaches, M5 raises access-point outages, neither touches the `alarms` table. Both guard against repeats — one alarm per breaching ticket ever, outages only on the transition — because a sweep that re-alarms every minute is how a bell gets muted.

**Alarms are scheduled and invisible until due.** A reminder written a week early is neither shown nor counted until it comes due, or the badge would be wrong all morning.

**The day-of-week trap.** Carbon numbers Sunday as 0 but `startOfWeek()` is Monday, so a naive conversion shifts the whole roster by six days and looks like the generator not working. There is a test for Sunday specifically.

**One bug found in an old test, not new code.** `AppShellTest` asserted an assistant *falls back* to the profile page because `assistant.shifts.today` did not exist. It does now, so the test's premise expired — rewritten to assert the real behaviour, with the fallback still covered by the no-role case.

---

## Sprint 0 — complete

Scaffolding, finished 2026-08-28. Kept for the record; nothing here is outstanding.

| # | Stage | Status |
|---|---|---|
| 0 | Repository, gitignore, branch, docs committed | ✅ 2026-08-27 |
| 1 | Laravel install, `.env`, Breeze with register removed, Vite + Tailwind + Alpine + Chart.js | ✅ 2026-08-27 |
| 2 | `config/atlas.php`, all eleven enums, `EnsureUserHasRole` middleware | ✅ 2026-08-27 |
| 3 | Layouts, role-filtered sidebar, 23 shared Blade components | ✅ 2026-08-28 |
| 4 | Foundation migrations, `ReferenceSeeder`, empty `DemoDataSeeder` | ✅ 2026-08-28 |

> One Sprint 0 exit condition is still open: **all seven members have run the app successfully.** Until that happens the setup steps in `README.md` are unverified by anyone but the author, and they become the Installation Guide in the report appendix.

---

## Not code, and on a deadline

These do not move when modules get built, and one of them has a date.

| | Status |
|---|---|
| **Design freeze — date unknown** | 🟠 Context Diagram, DFD 0 and the consolidated ERD. **15 marks, not started.** The 15 Sep 2026 date was invented, not taken from the brief — see coursework/09-roadmap.md. Still needed; the urgency is unverified. |
| **Q3 — ticket lifecycle** | 🟠 Adopted as proposed and built in M4, but **never actually agreed by the team.** Confirm it or change it — the code is written to make either cheap. |
| **Module owners** | 🔴 Seven unassigned. **Four modules are now built with nobody named as their author.** Blocks the individual report deliverables. |
| **Per-module diagrams** | 🔴 DFD 1, data dictionary and flowchart now owed for M1, M2, M3 and M4. |
| **Weekly progress reports** | 🟠 10 marks, due every week to week 12. See the files in this folder. |

---

## Environment on the development machine

**Re-verified 2026-08-28. Everything below is installed and working — nothing needs installing.**

| Tool | Status | Notes |
|---|---|---|
| PHP | ✅ 8.2.12 | XAMPP, at `C:\xampp\php\php.exe`. Laravel 12 needs `^8.2`, so this is fine. |
| MariaDB | ✅ 10.4.32 | XAMPP, at `C:\xampp\mysql\bin\mysql.exe`. **Start it from the XAMPP Control Panel** before any `migrate` command. |
| Node | ✅ 24.15.0 | For Vite |
| npm | ✅ 11.12.1 | |
| Git | ✅ 2.54.0 | |
| **Composer** | ✅ **2.10.2** | Installed 2026-08-27 to `C:\xampp\php\composer.phar`, with a `composer.bat` wrapper and a POSIX shim so `composer` works in cmd and Git Bash alike. |
| **GitHub CLI** | ✅ **2.98.0** | Installed and authenticated as `phone292025`. |

> This table was left saying "Composer ❌ Missing" for a day after Composer was installed — the session log recorded the install, the summary at the top did not. Someone read the table and set out to install it again. **If you change the environment, change this table in the same session.** A summary that contradicts the log below it is worse than no summary.
>
> Other team members will still need PHP, MariaDB, Node and Composer. The verified setup steps are in `README.md` and become the Installation Guide in the report appendix.

---

## Session log

Oldest first — append new entries at the end. One entry per development session: what was done, what was decided, what is next.

### 2026-08-27 — Stage 0

**Done**
- Documentation committed (`4e86655`) — 35 files, `docs/` is now under version control
- Sprint 0 stance on Q3 and Q6 recorded in the decision log (`7e0b1a7`) without flipping either question to `Decided`, since the team has not ratified them
- `.gitignore` (Laravel-aware) and `.gitattributes` added (`d07f19a`)
- Branch renamed `master` → `main` to match the docs
- This build log created and wired into `docs/INDEX.md`

**Found** *(historical — both were installed the same day; see the Stage 1 entry and the environment table above)*
- Composer is **not installed** on the development machine. Stage 1 cannot start without it.
- GitHub CLI is not installed, so a remote repository must be created through the website or after installing `gh`.
- XAMPP provides PHP 8.2.12 and MariaDB, which is sufficient for Laravel 12.

**Decided**
- Sprint 0 split into five reviewable stages, recorded above.
- Provisional-values rule for blocked questions: values already documented in `04-database.md` and `03-architecture.md` may be written into enums and config, marked provisional in a code comment, but **no behaviour that depends on an undecided question is built**. So `TicketStatus` cases and `atlas.sla_hours` can exist; the state machine, transition guards and SLA breach logic cannot. Same for Q6: `register` removed and `must_change_password` column created, but no first-login enforcement middleware.

**Next**
- Install Composer, then Stage 1.

**Still open**
- Q3 (ticket lifecycle) and Q6 (account creation) remain `Open`. Neither blocks Sprint 0 under the provisional-values rule above, but both must be settled before M1 and M4 start.

### 2026-08-27 — Stage 1 (in progress)

**Environment resolved**
- Composer 2.10.2 installed to `C:\xampp\php\composer.phar`, with a `composer.bat` wrapper and a POSIX shim so the `composer` command works in both cmd and Git Bash. Installer signature verified against `composer.github.io/installer.sig` before running.
- PHP extension `zip` is **not enabled** in this XAMPP build. Composer falls back to a slower extraction path but works. Worth enabling in `php.ini` if installs feel slow.
- MySQL is **not currently running**. Start it from the XAMPP Control Panel before any `migrate` command.

**Version decision**
- Laravel **13.10.1** is current but requires PHP `^8.3`; this machine has PHP 8.2.12. Pinned to **Laravel 12** (installed 12.68.0), which requires `^8.2`. Breeze 2.4.2 supports it. Recorded as [ADR-0008](../decisions/ADR-0008-laravel-12-pinned.md).
- **Nobody runs `composer update`.** Use `composer install` so everyone matches the committed lock file.

**Done**
- Laravel 12.68.0 installed at the project root alongside `docs/`
- Project `README.md`, `.gitignore` and `.gitattributes` preserved over Laravel's versions; `*.log` added to ours
- Default SQLite database removed; `.env` and `.env.example` switched to MySQL, database name `atlas_netcare`
- `ATLAS_TELEMETRY_DRIVER`, `ATLAS_DEMO_MODE` and `ATLAS_POLL_INTERVAL` added to `.env.example` with explanatory comments
- Breeze 2.4.2 installed with the **Blade** stack, bringing Tailwind 3.4.19, Alpine 3 and Vite 7
- **Registration removed** per Q6 working assumption: `register` routes, `RegisteredUserController`, `register.blade.php`, `RegistrationTest` and the welcome-page link are all gone. A comment at the top of `routes/auth.php` explains why, so nobody re-adds it.
- **Self-delete-account removed**: Breeze lets a user delete their own account, which contradicts M1's "deactivate, never delete" and NFR-06 auditability. Route, controller method, form partial and test removed; `ProfileTest` rewritten to assert DELETE `/profile` returns 405.
- Chart.js installed and wired into `resources/js/app.js` with shared defaults, so every graph in the system looks consistent
- Unused `@tailwindcss/vite` v4 dependency removed — Breeze installed a Tailwind **3** setup with `tailwind.config.js`, which is what `06-ui-conventions.md` already specifies
- `README.md` now carries verified setup steps for teammates; these become the Installation Guide in the report appendix

**Verified**
- `php artisan test` — 22 passed, 49 assertions
- `php artisan view:cache` — every Blade template compiles
- `php artisan route:list` — 17 routes, no `register`, no `profile.destroy`
- `npm run build` — Vite build succeeds

**Not yet done**
- MySQL was not running, so the `atlas_netcare` database has not been created and no migration has been run against MySQL. Tests use in-memory SQLite, so they pass regardless. **Start MySQL from the XAMPP Control Panel before Stage 4.**

**Resolved same day**
- MySQL started. XAMPP ships **MariaDB 10.4.32**, not MySQL proper. Laravel 11+ has a dedicated `mariadb` driver that is more accurate than the generic `mysql` one currently in `.env` — worth switching before M7, because MariaDB implements `JSON` as `LONGTEXT` with a check constraint rather than a native type, and some JSON query methods behave differently. **Still to do.**
- GitHub CLI authenticated as `phone292025`. Private repository created at **https://github.com/phone292025/atlas-netcare**, `main` pushed and tracking `origin/main`.
- Collaborators **not** added yet, at the group's request. Decide whether the group leader should own the repository before inviting the other six.

**Next**
- Stage 2: `config/atlas.php`, the eleven enums, `EnsureUserHasRole` middleware.

### 2026-08-27 — Stage 2

**Done**
- `config/atlas.php` written to the specification in `03-architecture.md`, plus the navigation map from `06-ui-conventions.md`. Every value carries a comment explaining what it does and which document or open question it comes from.
- All **eleven enums** created in `app/Enums/`, matching the documented list exactly. Each has `label()`; every status enum also has `tone()` returning one of `healthy` / `attention` / `urgent` / `inactive`, which `<x-status-badge>` will map to Tailwind classes in one place (Stage 3).
- Shared `HasValues` trait giving every enum `values()` and `options()` — used by seeders, factories and select inputs. One file rather than 33 lines duplicated across eleven.
- `EnsureUserHasRole` middleware, registered as the `role` alias in `bootstrap/app.php`. Accepts a comma list: `role:engineer,admin`.
- `app/Http/Middleware/` had to be created — Laravel 12 ships no middleware directory by default.

**Provisional values marked in code**
- `atlas.thresholds` carries a PROVISIONAL comment pointing at Q1.
- `atlas.sla_hours` and `TicketStatus` carry PROVISIONAL comments pointing at Q3, and `TicketStatus` explicitly names what must *not* be built yet: the state machine, transition guards and SLA breach detection. Anyone opening that file sees the constraint.

**Verified**
- `php artisan test` — **93 passed, 341 assertions** (up from 22)
  - `EnumContractTest` — every case has a label, every backing value is lower snake_case, every status tone is one of the four known tones, `values()` and `options()` stay in step
  - `RiskBandTest` — banding pinned at every boundary, including that retuning `config` changes the outcome without touching code
  - `AtlasConfigTest` — risk weights sum to 1.0, bands are ordered, every priority has an SLA, more urgent priorities have shorter SLAs, every role has navigation
  - `EnsureUserHasRoleTest` — matching role passes, non-matching forbidden, multi-role lists work, no role forbidden, deactivated user forbidden, guests redirect to login rather than 403
- `php artisan about` — application boots with the new middleware alias registered

**Known limitation**
- `users.role` and `users.is_active` do not exist as columns yet; they arrive with the M1 migration in Stage 4. The middleware tests set them as in-memory attributes, which exercises the logic fully but is not the same as testing against the real schema. Re-check these tests once the migration lands.

**Deferred**
- `routes/api.php` is not registered in `bootstrap/app.php`. Laravel 12 omits it by default, and `php artisan install:api` would pull in Sanctum, which this project does not need — the dashboard polling endpoint is session-authenticated from the same browser. Decide in M5 whether to register `api.php` manually or put the endpoint in `web.php`.

**Next**
- Stage 3: layouts, role-filtered sidebar, shared Blade components.

### 2026-08-27 — Stage 2 review and fixes

An independent review of Stage 2 found a real defect. All findings were re-verified against the code before acting on any of them.

**Correctness — fixed**
- `EnsureUserHasRole` failed **open** on deactivated accounts. `$user->is_active === false` compares strictly against a value Eloquent returns as `int(0)` when no cast is configured, so the guard never fired and FR-106 was silently broken. Proven with a throwaway probe (`type: int, value: 0`), then fixed to `! ($user->is_active ?? true)`.
- The original test suite could not have caught it: it assigned a PHP `false` in memory, which makes strict comparison work. Two regression tests were added that load the user **back out of the database**, and were validated by reintroducing the bug — they fail against it and pass against the fix.

**Tooling — fixed**
- `@dataProvider` doc-comments replaced with `#[DataProvider]` attributes. Deprecated in PHPUnit 11.5.56, removed in 12. The warnings were printing on every run; earlier sessions missed them because the test output was grepped down to `PASS|FAIL|Tests:`. **Do not filter test output that aggressively.**
- `vendor/bin/pint` now passes clean. `config/atlas.php` had aligned `=>` arrows that the Laravel preset un-aligns, and `ProfileController` still carried an unused `Auth` import from Stage 1 — a `sed` that was reported as done had silently failed.
- A `pint.json` with `align_single_space_minimal` was tried and **rejected**: it forces alignment across the entire codebase including Laravel's own files. Recorded in the decision log.

**Design — improved**
- `HasLabel` and `HasTone` interfaces added under `app/Enums/Contracts/`. `HasValues::options()` calls `label()`; without an interface a twelfth enum that forgot it would fail at render time rather than statically.
- `EnumContractTest` now **discovers** enums by globbing `app/Enums/*.php` instead of a hand-maintained list, so a new enum is covered the moment it exists. It also asserts the discovered set still matches the eleven the docs promise. Note: data providers run before Laravel boots, so `app_path()` is unavailable — the glob walks up from `__DIR__`.

**Documentation — corrected**
- `03-architecture.md`: enum `Concerns/` and `Contracts/` added to the folder tree; `navigation` added to the config snippet; casts and Pint formatting noted.
- `ADR-0004`: corrected the claim that the schema has eleven enumerated fields. Eleven get an enum class; three more (`locations.type`, `checklist_items.category`, `telemetry_readings.source`) are enumerated in the schema but deliberately do not, because nothing branches on them. Added a warning that `telemetry_readings.source` (`simulated`/`live`) is **not** the same vocabulary as `atlas.telemetry_driver` (`simulated`/`snmp`).
- Minor: `RiskBand::fromScore()` docblock claimed scores were clamped; nothing clamps, the comparison chain handles out-of-range on its own. Redundant ternary removed from `AtlasConfigTest`.

**Verified**
- `php artisan test --display-deprecations` — **107 passed, 357 assertions, zero deprecations**
- `vendor/bin/pint --test` — passes
- Regression tests confirmed to fail against the reintroduced bug

**Lesson worth keeping**
Three separate `sed`/`perl` edits in this project have silently failed on backslashes in PHP namespaces and been reported as done. When editing PHP `use` statements from the shell, use `\x5c` for the backslash or an editor tool — and check the result, do not assume it applied.

### 2026-08-27 — Second review pass

A follow-up review found seven issues. All were verified against the code before acting; all seven were real.

**Corruption — fixed**
- `docs/INDEX.md` had been broken since Stage 0 (`d07f19a`). A botched `perl` edit left three stray table rows above the file and glued `# Documentation Index` onto the end of line 3, so the H1 rendered as body text. This is the first file every agent and teammate is told to open, and it sat broken through two stages without anyone noticing. Repaired.

**Overclaiming — corrected**
- The middleware comment and commit message read as though FR-106 was satisfied. It is not: `LoginRequest::authenticate()` has **no** `is_active` check, and **no route currently uses the `role` middleware**, so a deactivated user can still sign in and reach everything that exists today. The guard fix was real; the traceability claim was premature. Comment rewritten, and FR-106 marked **partially built** in `01-requirements.md`.
- Two docblocks described code that does not exist, in the present tense: `atlas.php` said the driver is "Bound in AppServiceProvider" (that provider is empty) and `UserRole::homeRoute()` referred to `DashboardRedirect` (no such class). Both now say PLANNED and name the stage that will build them.

**Coordination risk — closed**
- Twelve route names were coined in `config/atlas.php` during Stage 2 and recorded nowhere else; `06-ui-conventions.md` documented only the assistant menu. With seven owners naming their own routes, and a sidebar that **silently skips unregistered routes**, a mismatch would make a menu item vanish with no error. All eighteen route names are now a documented table in `06-ui-conventions.md`, mapped to the owning module.

**Design tokens — settled before Stage 3 bakes them in**
- The tone `inactive` is renamed **`neutral`**. It has to cover both "out of play" (`retired`, `maintenance`) and "deliberately quiet" (`low` priority, `scheduled`); `inactive` was wrong for a low-priority ticket, which is very much active.
- `TicketStatus::Open` and `Reopened` were `urgent`, making every brand-new ticket render the same red as an offline access point. All outstanding ticket states are now `attention`. **Status carries progress; priority carries urgency.**
- `ShiftStatus::CheckedIn` was `attention` — a shift running normally is a good state. Now `healthy`.
- `AlarmType` keeps `HasTone` (severity in the notification list is genuinely useful), but the docs no longer describe tone as status-only, and `<x-status-badge>` is specced as accepting any `HasTone` enum.
- The design-token table in `06-ui-conventions.md` is now the authority and covers every case, so Stage 3 has nothing left to guess.

**Minor**
- `glob()` returns `false` on failure, which would have produced a `TypeError` instead of a clean assertion failure. Now `glob(...) ?: []`.
- `AGENTS.md` still claimed 93 tests. It is 107.

**Verified**
- `php artisan test --display-deprecations` — 107 passed, 357 assertions, zero deprecations
- `vendor/bin/pint --test` — passes
- All internal documentation links resolve

**Pattern worth noting**
Both review passes found defects that the test suite reported as passing, and this one found a corrupted file that had survived two stages of "verified" work. Green tests and a clean Pint run say nothing about whether the docs are true or the claims are honest. Read the artefacts.

### 2026-08-27 — Stage 3a: application shell

Stage 3 is the largest of the five, so it is split. **3a is the foundation every component and screen sits on**; 3b and 3c build the remaining component library on top of it.

**Done**
- `tailwind.config.js`: a `brand` (indigo) palette for links, primary buttons and active navigation — deliberately none of the four status hues, so a coloured button never reads as a status. Semantic colour is **not** defined here; see below.
- `<x-icon>`: 17 hand-built inline SVGs on one 24x24 / 1.5-stroke grid. No icon package — eleven navigation icons do not justify a dependency. An unknown name renders a visible "?" so a typo shows on screen instead of leaving a gap.
- `<x-badge>` — **the single place that turns a tone into classes.** `<x-status-badge>` and `<x-priority-pill>` both delegate to it, so `urgent` is one red everywhere. An unrecognised tone renders fuchsia on purpose: a bug should be visible, not silently grey.
- `<x-sidebar>`: role-filtered navigation built from `config('atlas.navigation')`. Active state matches the whole `admin.users.*` section so create/edit screens keep their parent highlighted.
- `layouts/app.blade.php`: full shell — fixed sidebar on desktop, Alpine slide-over drawer on mobile, sticky topbar, user block with role, sign-out. Replaces Breeze's top-nav.
- `DashboardRedirect` + `/home`: sends each role to its own landing screen, falling back to the profile page for routes that do not exist yet. This is the class `03-architecture.md` referenced and `UserRole::homeRoute()` was written for — both now real rather than PLANNED.
- Breeze leftovers removed: `layouts/navigation.blade.php` and `dashboard.blade.php`. The `dashboard` route is replaced by `home`; nine references updated.
- `profile/edit.blade.php` rebuilt — it had unbalanced `<div>`s and an empty card left behind when the delete-account form was removed in Stage 1.

**The tone → class mapping deliberately does not live in config**
Tailwind scans source files for class names. A class assembled at runtime from config (`bg-{$tone}-50`) is never seen by the scanner, gets purged from the build, and renders unstyled. So the mapping is literal strings inside `badge.blade.php`. This is the one place a "no magic values in code" instinct would produce a broken build.

**A layout bug that 120 passing tests did not catch**
The sidebar was written with `-translate-x-full` and `lg:translate-x-0`. Both utilities set `--tw-translate-x`, the negative one won the cascade, and **the sidebar sat off-screen on every desktop screen** — a 256px empty gutter with no navigation. Every test passed, because they assert on rendered HTML and cannot evaluate CSS.

Found by opening the page and measuring `getBoundingClientRect()`. Fixed by scoping the transform to `max-lg:`, so desktop has no transform at all. A regression test now pins the class names, since HTML assertions are the only lever available.

**Verified in a real browser**, not just in tests:
- Desktop 1280px — sidebar at `left: 0`, content offset 256px, no horizontal overflow
- Mobile 375px — drawer closed at `-256`, opens to `0`, closes back to `-256`, no horizontal overflow
- No console errors; Alpine 3.16.3 initialising correctly

> Measuring animated properties in this browser pane is unreliable — when the pane is not displayed the compositor pauses and CSS transitions never advance, so `getComputedStyle().transform` returns stale values. Remove the transition class before measuring position.

**Also done to make the app runnable**
- `atlas_netcare` database created; base Laravel migrations run against MariaDB
- No user accounts exist. The throwaway visual-check accounts were deleted on 2026-08-28; Stage 4 seeds the real first administrator. To create a temporary one before then:
  ```
  php artisan tinker --execute='App\Models\User::firstOrCreate(["email" => "demo@atlas.test"], ["name" => "Demo Engineer", "password" => bcrypt("password")]);'
  ```
- `.claude/launch.json` added (gitignored) so the dev server starts with one command

**Verified**
- `php artisan test --display-deprecations` — **121 passed, 386 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- `npm run build` — succeeds; CSS 35 KB → 52 KB as the new component classes are picked up

**Next**
- Stage 3b: page-header, card, empty-state, alert, modal, data-table, pagination
- Stage 3c: stat-tile, risk-gauge, chart, timeline, form.input, form.select, form.textarea, checklist-item

### 2026-08-28 — Stage 3b: page furniture

The seven components every screen is assembled from. **121 → 155 tests.**

**Built**
- `<x-page-header>` — title, subtitle, actions slot. Renders an `<h2>`: the layout owns the only `<h1>`, and two of them leaves a screen reader unable to tell which is the page.
- `<x-card>` — title, subtitle, header and footer slots, plus `flush` for content that manages its own edges. A table inside a normal card is inset twice.
- `<x-empty-state>` — title, message, icon, action slot.
- `<x-alert>` — success / warning / error, optional title, optional dismiss.
- `<x-modal>` — replaces Breeze's, which nothing referenced. Its focus trap was kept because tab containment is fiddly and theirs is correct; the header, footer slot, close button and `role="dialog"` / `aria-modal` semantics are new.
- `<x-data-table>` — headers array, rows as plain `<tr>`, plus `filters`, `empty` and `pagination` slots. Sorting is a **link**, not JavaScript, so the controller orders the query and the sort survives a refresh, a back button and a shared URL.
- `<x-pagination>` — hand-rolled rather than `$paginator->links()`, whose stock Tailwind view uses its own greys and focus styles. Windowed to first / last / current ±1. Renders nothing on a single page.
- Nine icons added for the above — `check-circle`, `warning`, `x-circle`, `inbox`, `chevron-left/right/up`, `sort`, `plus`. **17 → 26.**

**The tone mapping moved, and had to**
`badge.blade.php` held the tone → class `match` because a badge was the only surface that needed it. An alert banner cannot reuse a badge's classes — different size, border, tinted icon — so the vocabulary moved to **`app/Support/Tone.php`** with one method per surface (`badge()`, `banner()`, `accent()`). `<x-risk-gauge>` in 3c is the third surface, and `<x-chart>` will need the same colours as JavaScript values, which Tailwind cannot supply at all.

That required `./app/**/*.php` in the Tailwind `content` array. **Verified in the built CSS** rather than assumed: all fourteen tone classes, including four that exist only in `Tone.php`, are present after a clean build. Delete that content line and every badge and alert in the system silently loses its colour.

`ToneTest` pins the property rather than the palette — badge, banner and accent must agree on the colour family for a given tone — so a deliberate recolour stays a one-line edit but a drifting one fails.

**Flash messages are the layout's job now**
The shell was carrying a hand-written emerald `<div>` for `session('status')`. It now renders `<x-alert>` for four keys — `status`, `success`, `warning`, `error`. Seven owners each inventing a banner was a guaranteed inconsistency.

**Two traps worth knowing**
- `ComponentSlot::isEmpty()` compares against `''` **exactly**, and a `@foreach` over an empty collection still emits the whitespace between its tags. The slot is blank on screen but not an empty string, so `<x-data-table>` trims before deciding. Without that, an empty list renders a table header sitting above nothing. There is a test for it.
- `<x-pagination>` needs the controller to call `->withQueryString()`. Without it, paging away from page 1 drops the filters and the sort the user just chose and the list appears to reset itself. The component deliberately does **not** do this itself — it would be mutating its caller's object as a side effect of rendering.

**Stale classes after deleting a view** *(cause corrected during the Stage 3b review — see below)*
Removing the scratch gallery and rebuilding produced a byte-identical CSS, still carrying `grid-cols-2` that no source used any more. The observation was real; the diagnosis was not. See the review entry below.

**Verified**
- `php artisan test --display-deprecations` — **155 passed, 496 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- `npm run build` from a cleared cache — 41.83 KB CSS
- `php artisan view:cache` — every template compiles

**Verified in a real browser**, desktop and mobile, on a scratch gallery page rendering all seven side by side (deleted before committing):
- Desktop — sidebar at `left: 0`, two-column card grid, sort indicators, pagination window `1 2 3 4 … 44`, no horizontal page overflow, no console output at all
- **375px** — drawer closed at `-256`, hamburger present, cards single column, page-header actions stacked below the title, and the table scrolling **inside its own container** (324px visible, 632px content) with the page body not moving sideways
- Modal — centred, backdrop dimming, header, footer; confirmed by rendering it with `:show="true"`

> **The window resize tool does not work in this browser pane** — it reports success and the viewport stays 1707px. Mobile was checked by injecting a 375px same-origin `<iframe>` of the page, whose media queries respond to the iframe viewport. Use that method for every future responsive check.

> Extending Stage 3a's note on this pane: Alpine's `x-transition` **never advances** here. Opening the modal by event left `show === true`, the body scroll-locked, the wrapper `display: block` — and the dialog frozen at the enter-*start* state (`display: none; opacity: 0; scale(.95)`). Synchronous watchers fire; anything driven by `requestAnimationFrame` does not. To check an animated component, render it in its open state instead of transitioning into it.

**Next**
- Stage 3c: stat-tile, risk-gauge, chart, timeline, form.input, form.select, form.textarea, checklist-item

### 2026-08-28 — Stage 3b review

Independent review of Stage 3b. Everything was re-verified against the code and in a browser rather than taken from the notes.

**Verdict: the work is sound.** One documented cause was wrong, one measurement does not reproduce, and one decision is now backed by a number instead of an assertion. No defects found in the components themselves.

**Verified independently**
- `php artisan test --display-deprecations` — 155 passed, 496 assertions, zero deprecations
- `vendor/bin/pint --test` — passes
- Every Blade template compiles
- Rendered all seven components on a scratch page at 1280px and at mobile width, then deleted the scaffold

What the browser confirmed, beyond what the tests assert:
- All four alert tones render with the right colour and icon; an unrecognised type renders **fuchsia**, so the visible-bug guard works end to end
- `TicketStatus::Open` renders amber and `TicketPriority::Low` renders slate — the tone corrections agreed before Stage 3 held all the way to the screen
- Sorting: `aria-sort="ascending"` appears on the sorted column only, its link toggles to `desc`, the other column stays `asc`, and `page` is dropped from both. Sorting from page 3 does not strand you on page 3 of a re-sorted list.
- Pagination range maths correct — "Showing 21 to 30 of 47"
- Desktop sidebar at `left: 0` with content offset 256; the Stage 3a cascade fix survived 3b
- No horizontal page overflow at either width; the wide table scrolls inside its own container

**Correction: the CSS size claim was misattributed**
The Stage 3b notes blamed Vite for retaining classes from deleted files and prescribed `rm -rf node_modules/.vite public/build`. That is not the cause. Both directories were cleared in each measurement below and the difference persisted:

| Same source files | CSS |
|---|---|
| `php artisan view:clear` first | **42,191 bytes** |
| `php artisan view:cache` first | **54,035 bytes** |

The real cause is `./storage/framework/views/*.php` in the Tailwind `content` array — a Laravel default that makes **compiled Blade views count as source**. So build output depends on view-cache state, and a deleted view keeps contributing classes until its compiled artifact is cleared. The fix is `php artisan view:clear` before `npm run build`. Decision log updated; the superseded entry is struck through rather than deleted.

The recorded "41.8 KB actual" also does not reproduce as an unconditional figure — it is the *views-cleared* number. Quote it as such in the report, with the condition attached.

**Confirmed: `./app/**/*.php` in the content array is the right call**
This looked over-broad, so it was measured. Narrowing to `./app/Support/*.php` produced 53,612 bytes against 53,675 — a **63 byte** difference, with all `Tone` classes still present either way. The broad glob costs almost nothing, and its failure mode (63 bytes of unused CSS) is far cheaper than the narrow one's: a future file outside `app/Support` holding class strings would be silently purged, which is the exact bug the path exists to prevent. Keep it broad.

**Notes on the components, none blocking**
- `<x-alert>` wraps every alert in `x-data`/`x-show` even when not dismissible. Harmless, and it fails safe if Alpine never loads, but the Alpine binding is only needed for the dismissible case.
- The unknown-type branch asks `<x-icon>` for `help`, which no case defines. It falls through to the "?" fallback, which is the intended outcome — worth knowing it is reached by fallback rather than by a defined case.

**Still outstanding, unchanged by this review**
- Q3 and Q6 remain `Open`
- Design freeze is **15 Sep**. Context Diagram, DFD 0 and the consolidated ERD are worth 15 marks and none exist yet.

### 2026-08-28 — Stage 3c: dashboards, forms and the inspection control

Stage 3 is complete. The component library is built; every module screen from here is assembled from it rather than written from scratch.

**Done — eight components**
- `<x-stat-tile>` — a headline number with an optional delta. **Tone is the caller's decision, never inferred from the trend.** Rising uptime is good and rising latency is bad; a component that guessed would get half a dashboard wrong. `trend` picks the arrow, `tone` picks the colour, independently.
- `<x-risk-gauge>` — a 0-100 score with its band, clamped so an out-of-range score cannot overflow its track. `role="meter"` rather than a decorative bar. Derives the band from the score when one is not supplied. **Renders the contributing factors sorted heaviest first** — a bare number is not analytics, and the factors are the whole reason ADR-0007 chose a weighted model over a trained one.
- `<x-chart>` — Chart.js wrapper. Height is explicit because `maintainAspectRatio` is false, so a container without one collapses and the chart silently does not appear. Accepts a `chart-data` window event so the 20-second dashboard poll can animate between states instead of replacing the canvas.
- `<x-timeline>` — who did what, when. Absolute timestamp in `datetime` and `title`, relative time as the text.
- `<x-form.input>`, `<x-form.select>`, `<x-form.textarea>` — plus an internal `<x-form.field>` wrapper the three share, so the label position, error treatment and aria wiring cannot drift apart.
- `<x-checklist-item>` — OK / Issue / N/A with a remark that appears only for Issue.

**Three new Tone surfaces**
`solid()` for gauge fills, `choice()` for `peer-checked:` states, `chart()` for hex — a canvas cannot use a Tailwind class, so that one surface leaves the class vocabulary while staying in the single source.

**Two traps hit and recorded**
- A `str_replace` was used to prefix `Tone::badge()` with `peer-checked:`. That assembles a class at runtime, Tailwind never sees it, and every selected choice on the inspection form would have rendered colourless. Replaced with `Tone::choice()`, written out in full. **This is the exact trap the docs warn about, walked into anyway.**
- A Blade comment was nested inside a Blade comment in the risk-gauge docblock. The inner closing token ended the outer one, so the usage example below it became live template code and threw at render. Both are in the decision log.

**Verified in a browser at both widths, not only in tests**
- Desktop 1280px — stat tiles, gauge with factors ordered correctly, Chart.js line taking its amber from `Tone::chart()`, form controls with required marker and placeholder, timeline with tone-coloured dots
- **Mobile 375px — checklist tap targets measured at 44px tall**, which is the accepted minimum touch target. No horizontal overflow. Tapping Issue reveals the remark; the other items stay collapsed.
- That mobile measurement is the evidence for **NFR-05**, and it is reproducible during UAT.

> A screenshot mid-review appeared to show two cards missing. They were present in the DOM at the correct position — the pane had served a stale frame, the same non-compositing artifact noted in Stage 3a. **Measure the DOM before believing a screenshot.**

**Verified**
- `php artisan test --display-deprecations` — **205 passed, 622 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- Clean build (`view:clear` first, per the corrected guidance) — CSS **47,333 bytes**

**Next**
- Stage 4: foundation migrations (`users`, `locations`, `lab_pcs`, `access_points`), `ReferenceSeeder`, empty `DemoDataSeeder`. That completes Sprint 0.
- Still open and unchanged: Q3, Q6, and the 15 Sep design freeze with Context Diagram, DFD 0 and the consolidated ERD outstanding.

### 2026-08-28 — Component gallery

**Problem this fixes.** Seventeen shared components existed and **nothing used them**. The only visitable pages were login, profile and the password-reset flow, because screens arrive with their modules in Sprints 1-4. Worse, both the 3b and 3c reviews built a throwaway scaffold to look at the components and then deleted it before committing — so the same page was built twice and left behind nothing.

**`/dev/gallery`**, registered **only when `APP_ENV=local`**, renders every component with its variants: page furniture, the four alert tones, every status enum as badges, dashboard tiles, the risk gauge with factors, a Chart.js line, the data table with sorting and pagination, all four form controls, the timeline, the inspection checklist, the modal, and all 27 icons including a deliberately invalid name.

Three reasons it is worth keeping rather than being a scaffold:
- Seven module owners can see what already exists instead of each building their own card
- It is the source of screenshots for the **Screens Design chapter** of the report
- A component that breaks shows up here before it shows up inside somebody's module

**It cannot leak.** `DevGalleryTest` asserts the test environment is not `local`, that `dev.gallery` is not registered, and that the URL 404s. If the guard in `routes/web.php` is ever loosened, those fail.

**Verified**
- `php artisan test` — **208 passed, 625 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- Rendered in a browser; no horizontal overflow

**Note for the next session**
Do not build a throwaway review page again. Add to `resources/views/dev/gallery.blade.php` instead.

### 2026-08-28 — Stage 1–3 audit

A review of everything built in Sprint 0 so far, checking the code against what the documents claim rather than assuming they agree.

**Dead code removed — six components**
`danger-button`, `dropdown`, `dropdown-link`, `nav-link`, `responsive-nav-link` and `secondary-button` had **zero** references. They belonged to Breeze's top-nav layout (deleted in 3a) and the self-delete-account form (deleted in Stage 1). Dead files are worse than clutter here: a module owner opening the components folder cannot tell which of two buttons to use. All 208 tests still pass without them.

**Documentation drift closed**
- `app/Support/` was **not in the architecture folder tree at all**, despite holding `Tone.php` — the single most rule-bound file in the codebase — and `FormField.php`. Added, with what each is for.
- `<x-form.field>` was missing from the component table in `06-ui-conventions.md`. Added and marked internal.

**AGENTS.md brought up to date.** It was accurate about Stage 0 and stale about everything since. Changes:
- **Stack section replaced with pinned versions** verified against the lock file: Laravel 12.68.0, PHP 8.2.12, Breeze 2.4.2, Tailwind 3.4.19, Alpine 3.16, MariaDB 10.4.32. It previously said only "Laravel", "Tailwind CSS", "MySQL" — and MySQL was wrong.
- **Four rules added to "Never do these"**, all learned the hard way in stages 1–3: never build a Tailwind class from a variable, never nest a Blade comment, never run `composer update`, never let a component query.
- **New "Before you commit" section** — the four commands in order, with why `view:clear` comes first and why `--display-deprecations` matters.
- **New "Verifying your work" section** — that a green suite has now let two real defects through, and to measure the DOM rather than trust a screenshot.
- **New "Editing files from the shell" section** — `sed`/`perl` silently mangle PHP namespaces and report success. This has happened six times; the rule is now written down rather than relearned.

**Outstanding, raised not resolved**
- **Two form vocabularies coexist.** Four auth views and two profile partials use Breeze's `x-text-input` / `x-input-label` / `x-input-error`; everything built since uses `x-form.*`. Both work, but seven module owners about to build roughly twenty forms need one answer, and consistency is directly marked. Migrating the six views would let four more Breeze components go.
- `DB_CONNECTION` is still `mysql` against a MariaDB server. Matters for M7's JSON column.

**Verified**
- `php artisan test` — 208 passed, 625 assertions
- `vendor/bin/pint --test` — passes
- Every Blade template compiles

**Follow-up the same day — the two form vocabularies resolved**

The audit above raised this and left it as a question. Looking at what Breeze's components actually do settled it on facts rather than taste:

- `<x-input-error>` rendered a floating `<ul>` with **no programmatic link to its input** — no `id`, no `aria-describedby`, no `aria-invalid`. A screen reader user tabbing to a failed login field heard "Email, edit text" and nothing about the failure. That is a real gap against NFR-10, not a style preference.
- `<x-text-input>` hardcoded `focus:border-indigo-500` instead of the `brand` token, so the login page would not follow a brand change.
- It used `border-gray-300` where every other form uses `border-slate-300` — **a visibly different neutral on the login page from the rest of the system.**

Three functional defects, so all seven views migrated to `<x-form.*>`.

**What the migration forced, and it was worth it.** `update-password-form` validates into a **named error bag** (`$errors->updatePassword`) because two forms share the profile page. The form components only read the default bag, so a straight swap would have rendered **no password errors at all** — silently. All four now take a `bag` prop. That capability was missing and would have bitten the first module owner who put two forms on one screen.

**Ten Breeze components removed in total** — six dead from the audit, plus `text-input`, `input-label`, `input-error` and `primary-button` once nothing referenced them. 23 components remain, all ours, all documented.

`verify-email.blade.php` still said *"Thanks for signing up!"*. There is no signup in this system — an administrator creates every account (FR-105). Copy corrected.

`AuthFormMigrationTest` pins what this had to preserve: login errors announced with the failing field, old input returned on rejection, the password form reading its own named bag, the profile form **not** picking up the password form's errors, and a scan asserting no Breeze form component ever comes back.

**Verified**
- `php artisan test --display-deprecations` — **213 passed, 750 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- Login, profile and both profile forms rendered in a browser
- Clean build (views cleared) — CSS 55,869 bytes

**Same day — Laravel's branding removed from the front door**

Opening `localhost:8000` as a **guest** showed Laravel's stock welcome page: the Laravel logo, "Deploy now", and links to Laravel's documentation and Laracasts. The audit above missed it because every check was made while signed in, and `/` only shows that page to guests.

It was worse one level down. Breeze's `<x-application-logo>` is **the Laravel logo SVG**, and `layouts/guest.blade.php` rendered it above the sign-in form — so the very first screen anyone opens carried the framework's branding rather than the project's. The guest layout also used `bg-gray-100` and `text-gray-900` where the rest of the system uses slate, so the page visibly changed shade the moment a user signed in.

For a submission assessed on "innovative and originality of the implemented system", the first impression being Laravel's marketing page is not a small thing.

**Fixed**
- `welcome.blade.php` **deleted**. There is no public landing page by design: Atlas NetCare is internal and privilege-based with no student-facing entry point (NFR-03, proposal Keynote). `/` now sends a signed-in user to their own home screen and everyone else to sign in.
- `<x-application-logo>` replaced with **`<x-brand-mark>`**, used by *both* layouts, so the sign-in screen and the signed-in shell cannot show different branding. A letterform rather than an SVG illustration — no asset to lose, no file to license, scales cleanly.
- `layouts/guest.blade.php` rebuilt on the slate palette with the brand accent, and it now says **"Accounts are created by a system administrator"** — so a new assistant does not hunt for a sign-up link that does not exist (FR-105).

`ExampleTest` — Laravel's stock smoke test asserting `/` returns 200 — was rewritten rather than deleted. It now asserts the redirect *and* that the sign-in page shows "Atlas NetCare" and does **not** show "Laracasts" or "Deploy now". If the framework branding ever returns, that test fails.

**Verified**
- `php artisan test --display-deprecations` — **214 passed, 756 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- Signed out and opened `/` in a browser: redirects to a branded sign-in screen

**Lesson**
Every visual check so far had been made while signed in. **Check the signed-out state too** — it is the first thing a marker sees, and it was the one part of the application still wearing the framework's clothes.

### Handover — next session starts Stage 4

**Sprint 0 is one stage from complete.** Stages 0–3 are done: repository, Laravel 12 + Breeze, config and enums, role middleware, the app shell, and all 23 shared components. 214 tests, Pint clean, everything pushed.

**The database currently has no user accounts at all.** The two throwaway accounts used for visual checks were deleted. That is deliberate — Stage 4's `ReferenceSeeder` creates the first real administrator, and a hand-made account would have muddied it. Until then nobody can sign in, which is the correct state rather than a fault.

**Stage 4 scope**
- Migrations for `users` (adding `role`, `is_active`, `tp_number`, `phone`, `must_change_password`, soft deletes), `locations`, `lab_pcs`, `access_points` — to the schema in `04-database.md`
- Model casts, including `'role' => UserRole::class` and `'is_active' => 'boolean'` (see the Stage 2 review — the middleware guard is written to survive a missing cast, but the cast should exist)
- `ReferenceSeeder` — first admin, campus locations, checklist templates, shift templates
- `DemoDataSeeder` — created empty, wired to `config('atlas.demo_mode')`

**Decide before writing the migrations**
- **`DB_CONNECTION` is `mysql` against a MariaDB 10.4.32 server.** Laravel 11+ has a dedicated `mariadb` driver. This matters for M7: MariaDB implements `JSON` as `LONGTEXT` with a check constraint rather than a native type, and some JSON query methods behave differently. Cheaper to settle now than after `risk_assessments` exists.
- **Q6 is still `Open`** and Stage 4 touches it directly: the `must_change_password` column exists in the schema, but whether a first-login enforcement middleware is built depends on the team's answer.

**Also still open, unchanged**
- Q3 (ticket lifecycle) — blocks M4 and M6
- Seven module owners unassigned
- **Design freeze 15 Sep**: Context Diagram, DFD 0 and the consolidated ERD are 15 marks and none exist yet

### 2026-08-28 — Stage 4: foundation schema and seeders. **Sprint 0 complete.**

**Decisions settled first, both deferred since Stage 1**
- **`DB_CONNECTION=mariadb`.** XAMPP ships MariaDB 10.4.32 and Laravel 12 has a dedicated `MariaDbConnector`. Settled *before* the first migration rather than after, because MariaDB implements `JSON` as `LONGTEXT` with a check constraint rather than a native type, and M7's `risk_assessments.factors` is a JSON column. Verified with `php artisan db:show`, which now reports `Connection: mariadb`.
- **Q6 handled under the provisional-values rule.** `must_change_password` exists as a column because `04-database.md` specifies it, and the seeded administrator is flagged with it — but **no first-login enforcement middleware was built**, because the team has not ratified Q6.

**Migrations — four, matching `04-database.md`**
- `add_atlas_columns_to_users_table` — a **separate** migration rather than an edit to Laravel's `create_users_table`, which is already merged and has been run on other machines. Hard rule 4.
- `create_locations_table`, `create_lab_pcs_table`, `create_access_points_table`

Constraints enforced by the database, not by form rules: unique `asset_tag`, `mac_address`, `ap_code`, `code`, `tp_number`; `restrictOnDelete` from assets to locations so a lab holding machines cannot be deleted; soft deletes on users, lab PCs and access points so retiring something keeps its history (NFR-06).

**The `is_active` cast finally landed.** The Stage 2 review found the role middleware failing **open** because an uncast boolean column returns `int(0)` and `=== false` never matches. The guard was fixed then to a loose check; the cast was recorded as outstanding. It now exists — and the guard stays loose on purpose, so removing the cast cannot reintroduce the bug. A new test pins the cast itself.

**Privilege is not mass assignable.** `role`, `is_active` and `is_demo` are deliberately absent from `User::$fillable`. A mass-assigned `role` turns any profile form into privilege escalation. Same rule on the asset models for `risk_score`, `risk_band` and `is_demo` — written by M7's scoring run and the seeder, never by a form. There is a test.

**Seeders — the split that makes the data strategy real**
- `ReferenceSeeder` **always runs**: the first administrator and six campus locations covering the three zones in the approved scope. Nothing it creates is tagged `is_demo`, so `atlas:purge-demo-data` will leave all of it alone. Records are written out explicitly rather than through factories — a location called "Level 6 IT Lab 01" should be readable in the file.
- `DemoDataSeeder` **only when `ATLAS_DEMO_MODE=true`**, and is intentionally empty at Stage 4: the tables it will populate arrive with M2–M6. It documents what goes in it and that every row must carry its flag.

> **Correction to the Stage 4 handover note**, which said `ReferenceSeeder` would seed checklist and shift templates. Those tables belong to M3 and M6 and do not exist yet, so it seeds the administrator and the locations only. The seeder carries a comment saying those sections go *there* when the migrations land, rather than in a new seeder.

**Credentials come from the environment.** `ATLAS_ADMIN_EMAIL` and `ATLAS_ADMIN_PASSWORD` in `.env`, never a committed default — a password in the repository is a credential shared with six people. Documented in `.env.example` with a warning.

**Factories** for `Location`, `LabPc` and `AccessPoint`, with a `demo()` state that applies the `is_demo` flag. The same factories power the tests and will power `DemoDataSeeder`, so there is one set of fixtures rather than two. Lab PC purchase dates spread across six years on purpose — age is one of M7's four risk factors, and a dataset where every machine is the same age would make the ranking screen look broken.

**Verified**
- `php artisan test --display-deprecations` — **233 passed, 793 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- `php artisan migrate:fresh --seed --force` — clean run, administrator and six locations created
- Seeded administrator resolves to `UserRole::Admin`, `homeRoute()` → `admin.users.index`, 9 navigation entries
- A feature test signs in as the seeded administrator end to end — the check that actually matters after `migrate --seed`

**Sprint 0 is complete.** All five stages done. The next work is M1–M7.

**Unchanged and still outstanding**
- **Q3** (ticket lifecycle) — blocks M4 and M6
- Seven module owners unassigned
- **Design freeze 15 Sep** — Context Diagram, DFD 0 and the consolidated ERD, 15 marks, still not started

### 2026-08-28 — M1: identity, roles and the audit log

**Done**
- `audit_logs` table, written by **`AuditObserver`** rather than by controllers. Models are registered in `AppServiceProvider::AUDITED`; adding one there is the only step, and every write is then recorded — including from a seeder, a console command or tinker. A call site cannot forget it because there are no call sites. Polymorphic, so M2–M7 need no schema change.
- `Admin\UserController` — index with search, role and status filters, whitelisted sorting, `withQueryString()` so paging keeps the filters. Create, edit, show. **No `destroy`**: accounts are deactivated, never deleted (FR-106, NFR-06).
- `UserPolicy` — an administrator cannot **deactivate or demote themselves**. With no self-registration that is the only way to end up with a system nobody can administer, and there is no recovery path.
- `Admin\AuditLogController` — read-only. No edit, no delete: a log somebody can change is not evidence of anything.
- Base `Controller` gained `AuthorizesRequests`; Laravel 12 ships it empty, so `$this->authorize()` was unavailable.

**Closed a requirement that had been half-built since the Stage 2 review**
FR-106 says a deactivated account *cannot sign in*. The role middleware only blocked guarded areas — a deactivated user could still get a session. `LoginRequest` now rejects them, **after** the credential check rather than before, so the message cannot confirm an address exists to someone guessing.

**Test correction**
`AppShellTest` stubbed a fake `admin.users.index`. M1 built the real one, so the stub was shadowing it. Removed — the test now asserts against the real route. **As each module lands, delete its stub rather than leaving a fake that can drift.**

**Verified**
- `php artisan test --display-deprecations` — **251 passed, 828 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- All three screens rendered in a browser, signed in as the seeded administrator
- The sidebar shows Users and Audit Log as live links with the six unbuilt modules struck through and marked "SOON" — the Stage 3a behaviour doing exactly what the review asked it to

**Next**
M2 — locations and asset inventory. Its tables exist from Stage 4, so it is three CRUD resources assembled from the component library.
