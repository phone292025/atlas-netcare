# Open Questions

> **Last updated:** 2026-11-01

Eleven things the approved proposal leaves undefined, or that building it exposed. Ten are settled. Each has a working assumption so nothing is blocked, but assumptions are not decisions: until a question is marked `Decided`, the code built on it is at risk of rework.

**Status key:** `Open` — no assumption safe enough to build on · `Assumed` — building on a stated default · `Decided` — settled, recorded, safe

**Never delete a resolved question.** Change its status and add the resolution. The history records why the code is the way it is.

---

## Summary

> **Nine of these were built from their working assumption rather than agreed by the capstone team.** Nothing is blocked and every one is cheap to change, but "settled" below means *implemented*, not *ratified*. Q1 (the degraded thresholds) and Q3 (the ticket lifecycle) are the two most worth confirming, because the most code rests on them. Q6 is the one exception: it was put to the project lead and answered, on 2026-09-04.

| # | Question | Status | Built in | Where to change it |
|---|---|---|---|---|
| [Q1](#q1) | What counts as a degraded access point? | ✅ Settled | M5 | `config/atlas.php` → `thresholds` |
| [Q2](#q2) | Is the checklist fixed or admin-editable? | ✅ Settled | M3 | `ChecklistService` |
| [Q3](#q3) | What is the full ticket lifecycle and SLA? | ✅ Settled | M4 | `TicketService::TRANSITIONS`, `config/atlas.php` → `sla_hours` |
| [Q4](#q4) | Are shifts recurring or assigned individually? | ✅ Settled | M6 | `ShiftService::generateWeek()` |
| [Q5](#q5) | How does an alarm reach an assistant? | ✅ Settled | M6 | `AlarmService` |
| [Q6](#q6) | Who creates user accounts? | ✅ Decided | M1 | `EnsurePasswordIsChanged` middleware |
| [Q7](#q7) | Do lab PCs self-report health? | ✅ Settled | M2, M3 | — inspections are the only health source |
| [Q8](#q8) | Where do the NFRs live? | `Assumed` | Requirements | [01-requirements.md](01-requirements.md) → NFR-01, NFR-02 |
| [Q9](#q9) | Per-AP history or campus-wide trends? | ✅ Settled | M5 | `TrafficLogController` |
| [Q10](#q10) | How long is telemetry retained? | ✅ Settled | M5 | `config/atlas.php` → `retention` |
| [Q11](#q11) | How does a password reset email reach anyone? | ✅ Settled | M1 | `.env` → `MAIL_MAILER` and the SMTP settings |

---

<a id="q1"></a>
## Q1 — What numerically counts as a "degraded" access point?

**Status:** ✅ `Settled` — built as assumed in M5 on 2026-08-28 · **Blocks:** nothing further

> Built from the working assumption below, which nobody has ratified. **The numbers are five lines in `config/atlas.php` under `thresholds`** — changing them is a config edit, and `TelemetryClassificationTest` covers each threshold separately so you will see immediately what moves. The one thing still worth doing is sanity-checking 120 ms against an actual APU access point, even informally. Nobody has ever compared that number to reality.

The proposal promises to "highlight congested zones" but never defines congestion. Without numbers there is nothing to code and nothing to test.

**Working assumption**

| State | Condition |
|---|---|
| `degraded` | latency > 120 ms, **or** packet loss > 5%, **or** clients > 85% of AP capacity — sustained across 3 consecutive readings |
| `offline` | no reading received for 5 minutes |
| `online` | none of the above |

All values live in `config/atlas.php` under `thresholds`. The three-reading requirement prevents a single noisy sample from flipping an AP's state.

**To resolve:** agree the numbers as a team and record them here. Ideally sanity-check them against an actual APU access point, even informally.

---

<a id="q2"></a>
## Q2 — Is the PC checklist fixed, or can an administrator edit it?

**Status:** ✅ `Settled` — built this way in M3 on 2026-08-28 · **Blocks:** nothing further

A fixed list is one table and one seeder. An editable list is two tables and a CRUD area — and it gives the Admin module enough substance for its own DFD Level 1 and flowchart.

**Decision:** admin-editable **templates with versioning**, exactly as assumed. An inspection stores which template version it was completed against, so historical inspections stay meaningful after the checklist changes.

**Trade-off:** versioning adds one integer column and a rule that active templates are copied rather than edited. Cheap now, and it prevents the "why does this old inspection have items that no longer exist?" problem later.

**As built:** editing a template that has been used copies it to `version + 1`, carries the items across, and moves `is_active` to the copy — so an edit never leaves the system without an active checklist. An unused draft is edited in place, because there is no history to protect. `ChecklistService` is the only code that may version or activate, and `ReferenceSeeder` ships one active template so a fresh install can start a round on day one. See [`modules/M3-checklists-and-inspections.md`](modules/M3-checklists-and-inspections.md).

---

<a id="q3"></a>
## Q3 — What is the full ticket lifecycle, and is there an SLA?

**Status:** ✅ `Settled` — the proposal below was adopted and built in M4 on 2026-08-28 · **Blocks:** nothing further

> **Adopted rather than ratified.** The team never met on this, and M4 could not be built without an answer, so the proposal below was implemented exactly as written. **If the team wants a different lifecycle, say so — it is still cheap to change.** The state machine is one array in `TicketService::TRANSITIONS`, the SLA hours are three lines in `config/atlas.php`, and both are covered by tests that will tell you immediately what breaks.
>
> The two questions this section left open are answered by its own transition table: closing is "Reporter or engineer", so an engineer may close their own resolution — with the reporter able to reopen, which is what makes that safe. SLA was kept, per the recommendation below.

The proposal names three priorities and says a ticket runs "from the moment an IT assistant reports an issue until a network engineer closes it" — but not the states in between. This one answer drives the `tickets` table, the state machine, the SLA alarms, and roughly half the engineer interface.

**Proposed lifecycle**

```
open ──▶ acknowledged ──▶ in_progress ──▶ resolved ──▶ closed
                                              │
                                              └──▶ reopened ──▶ in_progress
```

| Transition | Who | Sets |
|---|---|---|
| → `open` | Assistant or system | `opened_at`, `due_at` from SLA |
| → `acknowledged` | Engineer | `acknowledged_at`, `assigned_to` |
| → `in_progress` | Assigned engineer | — |
| → `resolved` | Assigned engineer | `resolved_at`, `resolution_notes` (required) |
| → `closed` | Reporter or engineer | `closed_at` |
| → `reopened` | Reporter | clears `resolved_at` |

**Proposed SLA** — measured from `opened_at`, breach raises an alarm:

| Priority | Due within |
|---|---|
| `critical` | 4 hours |
| `medium` | 24 hours |
| `low` | 72 hours |

**Questions for the team:** should closure require the original reporter to confirm, or can an engineer close their own resolution? Is SLA worth having at all, or is it scope creep? *(Recommendation: keep it — it makes the alarm module meaningful and gives the reports something to measure.)*

**As built.** The machine refuses everything not in the table above — `open` cannot jump to `resolved`, and `closed` is final. That refusal is in `TicketService`, not in the UI: buttons render from `nextStates()`, so the screen never offers an illegal move, but a hand-written POST is rejected by the service regardless. Hiding a button is not a rule.

`due_at` is computed once at creation from `config('atlas.sla_hours')`. M6 raises the breach alarm; `Ticket::scopeBreaching()` is the query it should sweep, and it deliberately excludes resolved and closed tickets — a ticket that took too long has still breached, but nobody can act on it now.

---

<a id="q4"></a>
## Q4 — Are shifts recurring, or assigned one at a time?

**Status:** ✅ `Settled` — built as assumed in M6 on 2026-08-29 · **Blocks:** nothing further

**Working assumption:** weekly recurring **templates** (e.g. "Mondays 09:00–13:00, Level 6 IT Lab, 2 assistants") that generate dated `shifts` records one week ahead via a scheduled command. An admin can then override any individual shift.

Recurring templates mean the admin sets up a pattern once instead of entering shifts forever, and they make the roster generator a real feature rather than a data-entry form.

---

<a id="q5"></a>
## Q5 — How does an alarm actually reach an assistant?

**Status:** ✅ `Settled` — built as assumed in M6 on 2026-08-29 · **Blocks:** nothing further

"Alarm" in the proposal could mean an in-app badge, an email, a browser push, or a phone notification. Mobile push is out of reach for this project — it needs a native app or service worker infrastructure we do not have time for.

**Working assumption:** in-app notification bell with an unread count, backed by the `alarms` table and polled alongside the dashboard. Email is wired through Laravel's `log` mailer in development so it is demonstrable without an SMTP account, and can be switched to real SMTP with one `.env` change.

---

<a id="q6"></a>
## Q6 — Who creates user accounts?

**Status:** ✅ **`Decided`** 2026-09-04 · **Built in:** M1

The proposal's Keynote states the system is "for professional use only" and "will refrain from sharing with public." Taken literally that means **self-registration must not exist** — which changes what you install in week one.

**Working assumption:** install Laravel Breeze, then remove the `register` route and delete its view. Password reset stays. Administrators create every account via FR-401.

**Why it matters now:** getting this wrong means either building a registration flow nobody wants, or retro-fitting admin account creation after other modules already depend on `users`. Five minutes of agreement now saves a day later.

**Question for the team:** on first login, should a user be forced to change an admin-set password? *(Recommendation: yes, it is one middleware.)*

### Decided 2026-09-04: yes

`EnsurePasswordIsChanged` is appended to the `web` middleware group. A user carrying `must_change_password` is redirected to **Profile** from every other screen until they set their own password; `logout` and the profile screen itself stay reachable, so the control is not a trap. Changing the password through the profile form or through the emailed reset link both clear the flag.

**Why this was worth doing rather than deleting the column.** The flag was already written in three places and shown on the account screen as a badge reading "Must be changed at next sign-in", and `11-installation-guide.md` told anybody installing the system that the administrator "asks for a new one immediately". Neither was true. The column had been created under the provisional-values rule and the enforcement deliberately left out pending this answer, which was correct process, but it left two documents describing behaviour that did not exist. A visible control that does nothing is worse than no control.

Decided by the project lead rather than the full team, which is a weaker basis than Q1 to Q5 deserve but stronger than leaving a false claim on screen.

---

<a id="q7"></a>
## Q7 — Do lab PCs report their own health, or only get inspected?

**Status:** `Assumed` · **Blocks:** risk scoring inputs

Automatic reporting would require an agent installed on every campus lab machine. APU IT will not permit that, and it is not in the proposal's scope statement.

**Working assumption: inspection-only.** Every fact the system knows about a lab PC arrives through an assistant completing a checklist, or through a ticket raised against it. Risk scoring therefore uses inspection history, ticket history, and inventory age — not live machine telemetry.

It is a real constraint, and it explains why lab PC risk scoring works the way it does.

---

<a id="q8"></a>
## Q8 — Where do the non-functional requirements live?

**Status:** `Assumed`

The Meeting 2 minutes (07/08/2026) record an agreed annual downtime under 30 minutes and response time under 7 seconds. Neither number appears in the proposal body.

**Working assumption:** carried into [01-requirements.md](01-requirements.md) as NFR-01 and NFR-02, and used as explicit pass criteria in the test plan.

---

<a id="q9"></a>
## Q9 — Does "Traffic and Congestion Logs" mean per-AP history or campus-wide trends?

**Status:** ✅ `Settled` — built as assumed in M5 on 2026-08-28 · **Blocks:** nothing further

**As built:** both, from **one screen**. `engineer.traffic.index` unfiltered is the campus trend; filtered to one access point it is that AP's history. Two screens reading the same table would have been two things to keep in step. The chart above the table is grouped in the database rather than in PHP — a month of hourly rows across forty access points is ~29,000, and summing them in a collection works fine on seeded data and falls over on real volume.

**Working assumption: both**, served from the `telemetry_hourly` aggregate table rather than by scanning raw readings. At one reading per AP per minute across 40 APs, raw telemetry reaches roughly 1.7 million rows over 30 days — charting directly off that table will be slow enough to notice during a demo.

---

<a id="q10"></a>
## Q10 — How long is telemetry retained?

**Status:** ✅ `Settled` — built as assumed in M5 on 2026-08-28 · **Blocks:** nothing further

**Working assumption:** 90 days of raw readings, 12 months of hourly aggregates, pruned weekly by `atlas:prune-telemetry`.

**As built** exactly that, from `config('atlas.retention')`. The command deleted in chunks rather than one statement — a single `DELETE` across a million rows held the table long enough to notice, and it was scheduled to run while the system was up. `--dry-run` reported what would go without touching anything.

**Since 2026-10-28** the same limits are TimescaleDB jobs instead of a command (ADR-0011): raw readings are dropped a day-chunk at a time once older than 90 days, and hourly summaries after 12 months. `atlas:prune-telemetry` is gone.

---

<a id="q11"></a>
## Q11 — How does a password reset email actually reach anyone?

**Status:** ✅ `Settled` — built on 2026-08-29 · **Blocks:** nothing further

> **Update 2026-10-28: the Gmail setup below is gone.** `ATLAS_MAIL_ACCOUNT` and the plus-addressed accounts were removed. Mail is off by default (`MAIL_MAILER=log`), the demo accounts are back on `@apu.test` and `@atlas.local`, and any SMTP provider works once its settings are in `.env`. Check it with `php artisan atlas:mail-test`. Until mail is set up the reset screen says so instead of pretending, and opened at `localhost` on the machine running it, it shows the link. The rest of this answer is the record of what was built on 2026-08-29.
>
> **Update 2026-09-23:** the request form now gives the same answer whether or not the address has an account. "Requesting a reset for an unknown address says so", below, no longer holds.

Not in the original ten. Found when the reset screen was tried on a phone and said *"We have emailed your password reset link"* while sending nothing.

**FR-102 is approved** and the proposal lists "recover password" for all three roles, so it could not simply be dropped. The flow was never broken. Delivery was, for two separate reasons:

`MAIL_MAILER=log`, so Laravel wrote the message into `storage/logs/laravel.log`. Six reset links had accumulated there across four messages. That is also a small security problem, since a live token in a log file is a way into an account for anyone who can read it. Those tokens have been redacted in place; the error history around them was worth keeping.

And every demo account used `@apu.test` or `@atlas.local`, reserved domains that **can never** receive mail, so even a working mail server would have delivered nothing.

### What was built

**Gmail plus-addressing.** Every account now uses one real inbox:

| | Was | Is |
|---|---|---|
| Administrator | `admin@atlas.local` | `${ATLAS_MAIL_ACCOUNT}+admin@gmail.com` |
| Engineer | `phone@apu.test` | `${ATLAS_MAIL_ACCOUNT}+phone@gmail.com` |
| Assistant | `swan@apu.test` | `${ATLAS_MAIL_ACCOUNT}+swan@gmail.com` |
| Assistant | `nyan@apu.test` | `${ATLAS_MAIL_ACCOUNT}+nyan@gmail.com` |

Gmail ignores everything after a `+`, so four deliverable addresses land in one inbox somebody already owns. **No teammate has to hand over a personal address, and nothing real reaches the repository** — `ATLAS_MAIL_ACCOUNT` is one line in `.env`, and `.env.example` carries only the shape.

The rejected alternatives were Mailpit, which proves the code path but demonstrates a developer tool rather than an email arriving on a phone, and descoping FR-102, which contradicts an approved proposal and was not ours to decide quietly.

**The screen now distinguishes the two ways of being broken.** `config('atlas.mail_configured')` is false for `MAIL_MAILER=log` and equally false for `smtp` with no password, and the warning names which. It is behind `app.debug`, so it can never appear on a deployment.

**A refused SMTP login is an error message, not a stack trace.** `Password::sendResetLink()` sends inline, so Gmail's `535` for a bad app password surfaced as a 500 on the one screen reachable without signing in. `PasswordResetLinkController` catches it, logs the real reason, and shows the user a sentence. Verified against a live rejection from Gmail rather than only a mocked one.

**`php artisan atlas:mail-test` is the only real check.** The suite fakes the mailer, so a green run says nothing at all about whether SMTP works. The command sends one message, and on failure reads the server's wording and says which of the three usual causes it is: a rejected login, a blocked outbound port, or a missing CA bundle.

**The email itself is from this system.** Laravel's stock wording ends "If you did not request a password reset, no further action is required", which is written for a public signup form. On a system where an administrator creates every account, an unexpected reset email means somebody typed a colleague's address, and the right advice is to say so. The expiry is read from config rather than written into the sentence, because someone will change it.

### Still worth knowing

**Requesting a reset for an unknown address says so.** That is Laravel's default and it confirms which addresses exist on the system. On an internal tool with no self-registration the disclosure is small, and the alternative is a demo where a typo looks identical to success. **Left as it is on purpose.**

**The first message from a new app password usually lands in spam.** Look there before concluding it failed.

**An app password can be revoked from the Google account without anything here changing.** Run `atlas:mail-test` once before the demonstration.

---


## Resolution log

| Date | Question | Resolution | Recorded by |
|---|---|---|---|
| 2026-09-04 | Q6 — forced password change on first login | **Yes.** `EnsurePasswordIsChanged` on the `web` group. The column had been written since Sprint 0 and read by nothing, while the account badge and the installation guide both claimed it worked. | Project lead |
