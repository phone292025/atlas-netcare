# Testing

> **Last updated:** 2026-11-06

The test plan covers automated tests at three levels, with the data sets they use and the results they produced, and a protocol for four user acceptance sessions. **The UAT sessions have not been held.** They need real participants, and each one's feedback goes under [User Acceptance Testing](#user-acceptance-testing) when it is. Until then this page claims no UAT result.

---

## Four layers

| Layer | Target | Tool | When |
|---|---|---|---|
| **Unit** | Services with real logic | PHPUnit / Pest | As each service is written |
| **Feature** | One happy path and one authorisation failure per controller | Laravel feature tests | As each module is finished |
| **Browser** | Five core flows, end to end, against the Docker stack | Playwright ([ADR-0015](decisions/ADR-0015-browser-tests-with-playwright.md)) | Before committing a change to a screen or its JavaScript, and in CI |
| **UAT** | Real users completing real tasks | Manual, documented | Before a release that changes a screen people use daily |

Use the same factories that power `DemoDataSeeder`. Do not maintain a second set of fixtures.

## What to unit test

Not everything. Test the code where a mistake would be silent:

| Priority | Target | Why |
|---|---|---|
| **Highest** | `RiskScoringService` | Pure calculation with weights and clamping. A wrong number looks plausible. |
| **High** | `TicketService` state transitions | An illegal transition must be rejected by the service, not merely hidden in the UI |
| **High** | `TelemetryService` classification | Threshold and consecutive-breach logic |
| **Medium** | `InspectionService` overall-result derivation | Three outcomes, easy to get the boundary wrong |
| **Medium** | `ShiftService` generation | Date arithmetic across week boundaries |
| Low | CRUD controllers | Feature tests cover these adequately |

---

## Test case format

Every test case uses this shape. Fill in **Actual Result** as well as the expected one.

| Field | Example |
|---|---|
| **ID** | TC-M4-007 |
| **Module** | M4 — Ticketing |
| **Requirement** | FR-307 |
| **Objective** | An engineer cannot resolve a ticket without resolution notes |
| **Precondition** | Ticket TKT-2026-00042 exists in `in_progress`, engineer signed in |
| **Test data** | `resolution_notes = ""` |
| **Steps** | 1. Open ticket detail · 2. Click Resolve · 3. Leave notes empty · 4. Submit |
| **Expected result** | Form rejects with "Resolution notes are required"; status unchanged |
| **Actual result** | *(fill in during testing)* |
| **Status** | Pass / Fail |
| **Tested by / date** | |

Number cases as `TC-{module}-{seq}` so they trace back to modules and requirements.

## Coverage to aim for

At minimum, one test case per functional requirement in [01-requirements.md](01-requirements.md). There are 55 of them.

Include **negative cases**. A test plan that only proves things work when used correctly is a weak test plan.

---
---

## Four habits that came out of building this

Every one of these was learned by shipping a defect through a fully green suite. They show the difference between having tests and having *useful* tests.

**A route whose tests only assert failures has never been rendered.** M3 shipped three 500 errors onto routes that all had tests, every one asserting a 403 or a redirect. Nothing had ever fetched those pages successfully. Every screen now has a test that loads it, in its empty state as well as its populated one — empty is what every screen looks like on the day it ships.

**A test that only asserts labels has never checked the data.** M7's reports had tests asserting the words "Ticket volume" and "Hardware" appeared. They did — above a matrix where every cell read zero while the heading said "3 tickets". Assert the numbers, the totals, and that the parts sum to the whole.

**A regression test is checked against the bug.** Write the fix, write the test, then put the bug back and watch the test fail. A test that passes against broken code is worse than no test, because it makes you confident. The risk scoring model's clamp and its renormalising were both verified this way.

**A green suite on SQLite says little about the real database, so CI runs both.** `php artisan test` uses SQLite in memory because it is fast and needs nothing installed. On MariaDB, the database Atlas ran on until 2026-10-22, that gap shipped four defects: MySQL-only `FIELD()`, MySQL-only `DATE_FORMAT`, timestamp columns that rewrote themselves on every update, and rows already damaged before that was fixed. The first run on PostgreSQL found three more: date buckets written for MariaDB, `LIKE` on `json` columns, and email addresses that differed only in case.

CI runs both configs on every push to `main` and on every pull request:

```bash
php artisan test                        # SQLite
php artisan test -c phpunit.pgsql.xml   # PostgreSQL with TimescaleDB
```

The second needs the Docker stack's database running (`docker compose up -d db`). It uses a separate `atlas_test` database, because each run starts by dropping every table in the database it uses. From 2026-09-04 the second run was MariaDB, with `phpunit.mariadb.xml`, and it earned its place on its first run by failing a telemetry test that had been green for weeks while measuring the wrong thing. `SchemaIntegrityTest` still checks MariaDB's timestamp trap, so it skips itself on both runs now.

`php artisan atlas:check-schema` still audits the live database for schema traps and contradictory data, and is read only, so it is safe to point at real data. **Run it after any migration.**

## Browser tests

Feature tests read the HTML the server sends. They never run JavaScript or apply CSS, so they can't see a room plan that doesn't respond, a chart that never draws, or a printout in the wrong colours. Five flows and the accessibility checks run in a real browser instead, from `tests/Browser/`: the five sign-in tests once, then the other 18 in Chromium, the engine of Chrome and Edge, and again in Firefox, 41 in all:

| Spec | Flow |
|---|---|
| `sign-in.spec.js` | A wrong password is refused. The assistant, the engineer and the demo administrator land on their home screens. The first administrator, made by `atlas:create-admin`, has to choose a password before any other screen opens |
| `tickets.spec.js` | An assistant raises a ticket, picking the machine on the room plan, and an engineer acknowledges, starts and resolves it. The plan warns about a machine that already has an open ticket |
| `inspections.spec.js` | An assistant is asked to check in, checks in to their shift (FR-203), inspects the next machine in a room's round, and the round's count goes up by one |
| `monitor.spec.js` | The Network Monitor lists every access point, draws its chart, refreshes, filters by status and opens an access point's details |
| `report.spec.js` | The report's Print button calls the browser's print, and on paper the page has its title block and the light palette, even from dark mode |
| `accessibility.spec.js` | NFR-05 and NFR-10 on every screen of every role: each list screen, form, and one of each detail and edit screen, found from the links on its list. Nothing scrolls sideways at 375 px; axe-core finds no WCAG 2.1 A or AA violation in the light theme or the dark one ([ADR-0018](decisions/ADR-0018-axe-for-accessibility-checks.md)); the keyboard alone signs in and the first Tab offers "Skip to content"; Tab, at 1440 and 375 px, shows a visible change on every control it reaches, never gets stuck, and never lands on anything hidden or off screen; the phone drawer opens and closes from the keyboard. The checklist form needs a check-in, so `inspections.spec.js` runs axe on it |

```bash
npx playwright install chromium   # once
npm run test:browser
```

Four rules keep them honest:

- **They get a stack of their own.** `tests/Browser/global-setup.js` builds the image from the working tree, starts the Compose project `atlas-browser` on ports 8100 and 5433 with a copy of `.env.example` and its own image tag, seeds demo data and creates the administrator. The teardown deletes the stack. They never run against a stack with real data: they raise tickets, and a developer's `.env` may send real mail.
- **A console error fails the test.** The fixtures in `fixtures.js` collect JavaScript errors and failed requests from every page a test opens.
- **Compare the screen with itself, not with the seed.** The scheduler keeps simulating readings during a run, so an access point's status can change between two looks. The monitor test checks that the "Online" filter shows as many rows as its button counts, never that a given access point is online.
- **Firefox found what Chromium didn't.** Its first run failed six tests. One was Atlas's: the Unfinished list on the inspections screen was a grid with no column template, and Firefox sized its column to a truncated line's full width, 2 px past a 375 px phone; `grid-cols-1` lets it shrink. The other five were the tests assuming Chromium: headless Firefox leaves focus on the last control when Tab runs out, where Chromium wraps to the top, so the sweep now ends there only when nothing tabbable follows, and still fails on a Tab that doesn't move anywhere else; Playwright makes PDFs only in Chromium; and the inspection flow found the assistant already checked in by the Chromium run on the same stack. The phone-width test now names the element past the edge.
- **Each was watched failing.** Scoping the dark palette to all media instead of screens fails `report.spec.js` (text luminance 0.85 on paper), and renaming the room plan's script fails both ticket tests with console errors. The keyboard sweep's first run found the closed phone drawer's sixteen links taking focus off screen on every page, now fixed.

---


## Non-functional test criteria

NFR-01 and NFR-02 are pass conditions, not aspirations.

| ID | Criterion | How to test |
|---|---|---|
| NFR-02 | Page and API response under 7 seconds | `tests/Performance/load-stack.sh`: 12 people at once, ticket raises and an agent, for five minutes, on a campus-sized dataset, then a 150-device outage storm and a backlog of 5,000 readings. Measured: slowest request 736 ms; storm 7.1 s; backlog 933 ms. Capped at a small server's 4 CPUs and 4.5 GB: slowest 2.07 s; storm 7.5 s; backlog 2.7 s. See [Response times](#response-times). |
| NFR-05 | Checklist operable one-handed at 375 px | `accessibility.spec.js` loads every screen at 375 px and fails on sideways scrolling; `inspections.spec.js` completes a checklist. **Not done:** one real phone, with a screenshot, during UAT-1. |
| NFR-09 | Runs on current Chrome, Edge, Firefox | Every browser test runs in Chromium, the engine of Chrome and Edge, and in Firefox (`playwright.config.js`), locally and in CI. **Not done:** a pass in Edge itself, recording its version, during UAT. |
| NFR-10 | Keyboard reachable with visible focus | `accessibility.spec.js` tabs through every screen of every role, at 1440 and 375 px, and fails on a stop with no visible focus change, a stop that keeps focus, or focus off screen. axe checks labels, names, contrast and ARIA on the same screens. **Not done:** a pass with a screen reader (NVDA or Narrator), during UAT-4. |
| NFR-01 | Annual downtime under 30 minutes | Cannot be measured in 14 weeks. It is a design target with the mitigations that support it, not a measured result. |

> Do not claim to have measured NFR-01. Claiming an unverifiable result is worse than acknowledging the limit.

## The stack end to end

`tests/Stack/agent-https.sh` runs in CI's Docker stack job, after the backup and restore checks. On the stack as `.env.example` leaves it, an agent's batch over plain HTTP gets Atlas's 403. It then restarts the app with `ATLAS_SERVER_NAME=127.0.0.1`: the same batch over HTTPS through the image's Caddy is stored, plain HTTP is redirected by Caddy before PHP sees the token, and the real `atlas-agent` binary refuses Caddy's certificate until it is given Caddy's root as `ca_file`, and then stores its readings. Writing it found that an IP address as the server name broke every TLS handshake, since a client sends no name to an IP; compose now sets Caddy's `default_sni`.

`tests/Stack/backups.sh` runs in the same job, on the backup service the stack starts with `.env.example`'s `COMPOSE_PROFILES=backup` and an `ATLAS_BACKUP_COPY_DIR`. It waits for the first scheduled backup, then checks that the loop restored it into a scratch database and found it identical, dropped that database, copied the file to the copy folder, and wrote `backups/status`; that the service's health check then reads healthy; and that the scheduler's `atlas:check-backups` reads the backup as on time. Last it records a newer backup with no copy, and the scheduler must report it and alarm the administrator. Writing it found that an `environment:` block on the scheduler had replaced the shared one from compose's anchor, database settings included.

## Response times

NFR-02 is measured on a stack the size of a campus, not on the demo data:

```bash
bash tests/Performance/load-stack.sh            # build, seed, measure, remove
KEEP=1 bash tests/Performance/load-stack.sh     # leave the stack running on port 8300
```

The script builds a throwaway Compose project, `atlas-load`, on ports 8300 and 5436, from the working tree. It seeds the demo data, then `LoadTestSeeder`: 250 lab PCs, 150 access points, 3,000 tickets in every status and 4,000 finished inspections over 90 days. `atlas:backfill-telemetry` adds 30 days of readings every five minutes for each simulated device, and risk is recalculated. It then registers an agent watching all 150 access points (`DEVICES` changes the number).

`tests/Performance/response-times.mjs` signs in 12 people at once, a third each as administrator, engineer and assistant, each in their own session. For five minutes they open their role's heaviest screens in turn, as fast as the server answers. Every fourth step an assistant raises a ticket through the form instead, so writes, ticket numbering and the alerts a ticket raises run under the load too. Meanwhile the agent posts a batch of a reading per access point every second. A time runs from sending the request to the last byte of the body. The script fails if any request fails or takes longer than 7 seconds.

Then comes the **outage storm**: the agent reports all its access points unreachable, in three batches, because a device goes offline after three unreachable readings in a row. The clock starts before the first batch and stops when every one is offline and in an open incident and every alert those incidents raised has been sent, through the queue worker. The script fails if that takes more than a minute.

Last, the **backlog** (`tests/Performance/backlog.mjs`): five agents come back after an outage at once, each with 30 access points of its own, and each sends two batches of 500 buffered readings in a row, the most Atlas takes in one batch. It fails if a batch takes longer than 7 seconds or a reading is refused.

CI's **Response times** job runs a shorter version on every pull request and every push to `main`: a week of telemetry, 60 seconds of load, then the storm and the backlog, with the same budgets. The stack is removed however the run ends.

Measured 2026-11-05 on a Windows 11 laptop under Docker Desktop, with 1,358,835 readings, 3,002 tickets, 4,000 inspections, 250 lab PCs and 157 devices, and the agent watching all 150 access points:

| Request | n | p50 ms | p95 ms | max ms |
|---|---:|---:|---:|---:|
| Engineer: Network monitor | 704 | 291 | 315 | 554 |
| Engineer: monitor data (polled JSON) | 703 | 269 | 298 | 417 |
| Engineer: dashboard | 704 | 143 | 158 | 172 |
| Engineer: access points | 702 | 114 | 129 | 152 |
| Engineer: traffic logs | 702 | 132 | 154 | 176 |
| Engineer: ticket queue | 700 | 114 | 130 | 161 |
| Engineer: risk ranking | 700 | 147 | 166 | 186 |
| Engineer: reports | 700 | 269 | 291 | 377 |
| Administrator: monitor | 1,873 | 289 | 315 | 604 |
| Administrator: audit log | 1,870 | 69 | 81 | 231 |
| Administrator: lab PCs, access points, devices, users, roster | 9,353 | 48 to 63 | 60 to 74 | 110 to 260 |
| Assistant: monitor | 1,216 | 283 | 308 | 349 |
| Assistant: raise a ticket, the form (250-machine room plan) | 1,213 | 154 | 176 | 237 |
| Assistant: raise a ticket, the POST | 2,027 | 49 | 62 | 271 |
| Assistant: today's shift, inspections, tickets | 3,654 | 59 to 71 | 71 to 84 | 102 to 275 |
| Agent: POST a batch of 150 readings | 183 | 633 | 704 | 736 |

No request failed, and the slowest took 736 ms, about a tenth of the target. The monitor screens are the heaviest page loads, because they read every device's latest reading and its last day of hourly figures. A 150-reading batch is the heaviest request, at about 4 ms a reading.

An earlier run found a real fault: one ticket raise in 1,973 failed, because four assistants raising tickets at once all read the same highest ticket number and used up the three retries. Ticket numbers are now handed out under an advisory lock (`TableLock::named`), and the three runs since raised 1,980, 1,950 and 2,027 with none failing.

**Outage storm:** all 150 access points offline, in open incidents, and all 150 alerts sent, 7.1 seconds after the first of the three batches was sent. That covers the three requests, the listener on the queue, the incidents, the tickets and the alerts. The 40-device run took 3.5 seconds.

**Backlog:** five agents sending two batches of 500 readings each, at once, stored all 5,000 readings in 933 ms. The median batch took 434 ms and the slowest 512 ms.

### On a small server

`SMALL=1 bash tests/Performance/load-stack.sh` runs the same test with the stack capped by Docker at 4 CPUs and 4.5 GB between its services (`tests/Performance/small-server.yaml`): the app 1.5 CPUs and 1.5 GB, the database 1.5 CPUs and 2 GB, the worker and the scheduler half a CPU and 512 MB each. Measured 2026-11-06, same data and load:

| Request | n | p50 ms | p95 ms | max ms |
|---|---:|---:|---:|---:|
| Monitor screens and their JSON, every role | 1,110 | 935 to 977 | 1,335 to 1,489 | 1,819 |
| Engineer: reports | 151 | 882 | 1,312 | 1,507 |
| Every other screen | 3,412 | 568 to 839 | 963 to 1,218 | 1,608 |
| Assistant: raise a ticket, the POST | 316 | 647 | 1,106 | 1,460 |
| Agent: POST a batch of 150 readings | 139 | 1,157 | 1,532 | 2,069 |

No request failed, and the slowest took 2.07 seconds, under a third of the target. The twelve people got through an eighth as many requests as on the uncapped laptop, because each waits for its answer, so these are times under a load the small server could only just keep up with. The **outage storm** took 7.5 seconds for all 150 access points, their incidents and alerts, and the **backlog** of 5,000 readings 2.7 seconds, with the slowest 500-reading batch at 1.6 seconds.

This is one machine's measurement: a server with less memory than this laptop's Docker allowance will be slower, and the numbers to compare against are these, taken the same way.

---

## User Acceptance Testing

**Four sessions, with the feedback from each written down.**

### Who

Choose participants who resemble real users. Ideal mix:

| Session | Participant | Focus |
|---|---|---|
| UAT-1 | A student who has worked as an IT assistant | Inspection flow on a phone |
| UAT-2 | Another IT assistant or a peer | Ticket raising and shift check-in |
| UAT-3 | Someone technically minded playing the engineer | Dashboard, ticket queue, resolution |
| UAT-4 | A network engineer or lecturer | Whole-system walkthrough |

### Protocol

1. Reset to a known state: `php artisan migrate:fresh --seed`
2. Give the participant **tasks, not instructions** — "find out which access point in the library is struggling", not "click the dashboard link"
3. Do not help. Note where they hesitate; that is the finding.
4. Record: task, completed yes/no, time taken, observations, participant comments
5. Close with three questions: what was confusing? what was missing? would you use this?

### Document per session

Date · participant role · tasks attempted · completion rate · verbatim feedback · **issues found** · **what we changed as a result**

> The last one matters most. A UAT that produced no changes taught nothing. "The participant could not find the handover form, so it moved into the check-out flow" is the kind of result to write down.

---

## A fifth habit, from the end-to-end review of 2026-09-04

**Test the first run after a gap.** Every telemetry test started from a fresh database or a running collector. None started from a database the collector had not touched for two days, which is the state of every development machine on the morning of a demonstration. That state marked all 24 access points offline and raised 24 tickets, and the classification test had been asserting exactly that behaviour under the name "silent past the window is offline". A green test that pins a bug is worse than no test, because it argues for the bug. `test_the_first_run_after_a_collection_gap_raises_no_tickets` now holds the door.

---

## A sixth habit, from the end-to-end review of 2026-09-08

**Ask what the OTHER roles get.** Every access test in this project was written from inside one role: the owner opens their screen, and somebody who should not reach it gets a 403. That is two of the nine cells in a three-role, three-screen grid, and the six nobody looked at are where a screen 500s for the wrong actor or renders for one that should never see it.

`RoleAccessMatrixTest` walks 42 screens against all three roles and states the expected status for each. It found `assistant.inspections.show` returning a 500 on an unfinished inspection, on a route that already had tests — because every one of them handed it a *completed* inspection, which is the state the bug does not occur in.

Two smaller things came out of the same pass and are worth keeping as habits:

**A parameterised route needs a row before it can be tested at all.** `EveryScreenRendersTest` is deliberately empty-database, which means it can never load a `show` or an `edit`. Half the screens in the system were therefore only ever reached by tests that were checking something else.

**Count queries, do not read code for them.** A missing `->with()` renders identically to a correct one, passes every assertion, and simply gets slower as the inventory grows. `ListScreensDoNotNPlusOneTest` loads each list screen with 3 rows and then 30 and asserts the query count barely moves. Both this and the matrix were verified by breaking what they guard — a guard nobody has watched fail is a guard nobody knows works.

---

## A seventh habit, from the review of 2026-09-23

**Build fixture dates on the clock the code under test uses.** Two shift tests set "today" with UTC `today()` while the screen asked `CampusClock::today()`. They passed from 08:00 to midnight on campus and failed from midnight to 08:00, so CI went red for any push between 16:00 and 24:00 UTC. The factory already used the campus clock; the tests overrode it. Use `CampusClock::today()` in fixtures, and freeze the clock inside that eight-hour window when the behaviour depends on the date (`CampusClockTest` does). The same review found a backfill test that failed in the first minute of every hour, because "a minute ago" and "the last whole hour" swap order then. A test that reads the wall clock should set it.

**Test a control from every door, not only the one it was built for.** Deactivation was tested at the login form and on role-prefixed routes, and a test named "turned away from every screen" checked exactly one. The routes guarded by a Policy rather than a role still let a deactivated session raise tickets. `DeactivatedAccountTest` now goes through a shared route, a write, a JSON endpoint and the calendar feed.
