# Testing

> **Last updated:** 2026-08-30 · **Worth 10 marks in the report rubric**

Section F item 17 requires a test plan covering unit testing and user acceptance testing, with **data sets provided**, **actual results documented**, and **4 UAT sessions with recorded feedback**.

---

## Three layers

| Layer | Target | Tool | When |
|---|---|---|---|
| **Unit** | Services with real logic | PHPUnit / Pest | As each service is written |
| **Feature** | One happy path and one authorisation failure per controller | Laravel feature tests | As each module is finished |
| **UAT** | Real users completing real tasks | Manual, documented | Week 12 |

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

Every test case in the report uses this shape. Fill in **Actual Result** — the guidelines explicitly require it.

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

At minimum, one test case per functional requirement in [01-requirements.md](01-requirements.md). That is roughly 50 cases across seven modules — about 7 each, which is achievable if each owner writes theirs as they build rather than in week 12.

Include **negative cases**. A test plan that only proves things work when used correctly is a weak test plan, and markers notice.

---
---

## Four habits that came out of building this

Every one of these was learned by shipping a defect through a fully green suite. They are worth a paragraph in the report's testing section, because they show the difference between having tests and having *useful* tests.

**A route whose tests only assert failures has never been rendered.** M3 shipped three 500 errors onto routes that all had tests, every one asserting a 403 or a redirect. Nothing had ever fetched those pages successfully. Every screen now has a test that loads it, in its empty state as well as its populated one — empty is what every screen looks like on the day it ships.

**A test that only asserts labels has never checked the data.** M7's reports had tests asserting the words "Ticket volume" and "Hardware" appeared. They did — above a matrix where every cell read zero while the heading said "3 tickets". Assert the numbers, the totals, and that the parts sum to the whole.

**A regression test is checked against the bug.** Write the fix, write the test, then put the bug back and watch the test fail. A test that passes against broken code is worse than no test, because it makes you confident. The risk scoring model's clamp and its renormalising were both verified this way.

**A green suite says nothing about MariaDB.** The suite runs on SQLite in memory. That gap has now shipped four defects: MySQL-only `FIELD()`, MySQL-only `DATE_FORMAT`, timestamp columns that rewrote themselves on every update, and rows already damaged before that was fixed. `php artisan atlas:check-schema` audits the live database for all of it — schema traps and contradictory data — and is read only, so it is safe to point at real data. **Run it after any migration and before submission.**

---


## Non-functional test criteria

NFR-01 and NFR-02 are pass conditions, not aspirations.

| ID | Criterion | How to test |
|---|---|---|
| NFR-02 | Page and API response under 7 seconds | Browser network tab on the heaviest screens — AP dashboard, traffic logs, ticket queue with 300 tickets, risk ranking. Record actual milliseconds. |
| NFR-05 | Checklist operable one-handed at 375 px | Chrome device emulation at 375 px, plus one real phone. Record a screenshot. |
| NFR-09 | Runs on current Chrome, Edge, Firefox | Manual pass through the flagship flow in each. Record versions. |
| NFR-10 | Keyboard reachable with visible focus | Tab through every form and the ticket queue. Record any element that traps focus or shows none. |
| NFR-01 | Annual downtime under 30 minutes | Cannot be measured in 14 weeks. **State this honestly in the report** as a design target with the mitigations that support it, not as a measured result. |

> Do not claim to have measured NFR-01. Claiming an unverifiable result is worse than acknowledging the limit.

---

## User Acceptance Testing

**Four sessions are required.** Guidelines Section F item 17: "Perform 4 user acceptance testing and documented the feedback."

### Who

Choose participants who resemble real users. Ideal mix:

| Session | Participant | Focus |
|---|---|---|
| UAT-1 | A student who has worked as an IT assistant | Inspection flow on a phone |
| UAT-2 | Another IT assistant or a peer | Ticket raising and shift check-in |
| UAT-3 | Someone technically minded playing the engineer | Dashboard, ticket queue, resolution |
| UAT-4 | Mr Dhason or a lecturer | Whole-system walkthrough |

### Protocol

1. Reset to a known state: `php artisan migrate:fresh --seed`
2. Give the participant **tasks, not instructions** — "find out which access point in the library is struggling", not "click the dashboard link"
3. Do not help. Note where they hesitate; that is the finding.
4. Record: task, completed yes/no, time taken, observations, participant comments
5. Close with three questions: what was confusing? what was missing? would you use this?

### Document per session

Date · participant role · tasks attempted · completion rate · verbatim feedback · **issues found** · **what we changed as a result**

> The last one matters most. A UAT that produced no changes reads as a formality. Showing "participant could not find the handover form, so we moved it into the check-out flow" demonstrates the feedback loop the Agile methodology in your proposal claims.

---

## Before submission

- [ ] Every functional requirement has at least one test case
- [ ] Every test case has an **actual result** filled in
- [ ] Negative and validation cases included
- [ ] Unit tests pass — `php artisan test` clean
- [ ] NFR-02 timings recorded with real numbers
- [ ] 4 UAT sessions documented with feedback and resulting changes
- [ ] Screenshots of failures alongside successes
- [ ] Test data sets included, as the guidelines require
