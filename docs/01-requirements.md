# Requirements

> **Last updated:** 2026-09-02 · **Source:** approved proposal, section 1.3–1.5, extended by design decisions

Every requirement has an ID. Test cases reference these IDs; module specs list the IDs they satisfy. A requirement without an ID cannot be traced, so it cannot be marked.

**Status key:** `Approved` (in the submitted proposal) · `Added` (design phase, needs supervisor awareness) · `Assumed` (depends on an open question). **The status column records how a requirement was AGREED, not whether it is finished** — that is what this next paragraph is for.

## Build status, as of 2026-09-02

**Every functional requirement listed below has code behind it and at least one test.** The last two gaps closed on 2026-09-02: FR-307 was missing its *assign* verb, and FR-408 was missing the individual shift override.

Three carry a caveat worth stating rather than hiding:

- **FR-102** (password reset by email) works, but delivery depends on a Google app password being pasted into `.env`. Until it is, the screen says so instead of pretending. See [Q11](02-open-questions.md#q11).
- **FR-301, FR-303, FR-304, FR-305** all read **simulated** telemetry. The driver is swappable and the real one is a documented stub; see [10-simulated-and-real-data.md](10-simulated-and-real-data.md).
- **FR-506** alarms reach the in-app bell only, except shift reminders, which leave as a calendar file. Stated in the report Limitations.

The **non-functional** requirements are a different matter: NFR timings and the UAT sessions need real measurements and real participants, and cannot be produced from the code. They are listed in [11-submission-checklist.md](../coursework/11-submission-checklist.md) and remain outstanding.

---

## Scope

### In scope

- Wi-Fi access point monitoring in APU's high-traffic zones: **Library, IT Labs, Campus Cafeteria**
- Operational tracking of **university-owned lab assets** and internal maintenance workflows
- Incident reporting and resolution between IT assistants and network engineers
- Duty rostering and shift alarms for student IT assistants
- Predictive risk scoring for lab PCs and access points

### Out of scope

Stated in the proposal and not to be extended without supervisor agreement:

- Firewall configuration
- Bandwidth throttling
- Physical network cabling
- Technical servicing of students' personal devices
- Public or student-facing access — the system is privilege-based and internal only
- Any software agent installed on lab PCs (see [Q7](02-open-questions.md))

---

## Functional requirements

### FR-100 — Authentication and access (all roles)

| ID | Requirement | Status |
|---|---|---|
| FR-101 | A user can sign in with an email address and password. | Approved |
| FR-102 | A user can request a password reset link by email. | Approved — **built, but nothing is delivered yet.** The flow works end to end; the demonstration environment has no mail server and every demo account uses a reserved domain that can never receive mail, so the screen says so rather than claiming to have sent a link. Delivery is Gmail SMTP with plus-addressed demo accounts, built the same day — see [Q11](02-open-questions.md#q11). One manual step remains: an app password has to be pasted into `MAIL_PASSWORD` and checked with `php artisan atlas:mail-test`. Until it is, the screen says so instead of pretending. |
| FR-103 | A user can sign out. | Approved |
| FR-104 | The system denies access to any route not permitted for the user's role. | Approved |
| FR-105 | Self-registration does not exist. Accounts are created by administrators only. | Assumed — [Q6](02-open-questions.md) |
| FR-106 | A deactivated user cannot sign in but their historical records remain intact. | Added — **fully built.** `LoginRequest::authenticate()` refuses a deactivated account at sign-in, and the role middleware blocks one that already has a session. Both are tested. The note here said the login half did not exist; it did, and the claim was stale rather than the code incomplete. |

### FR-200 — Student IT Assistant

| ID | Requirement | Status |
|---|---|---|
| FR-201 | View own daily shift roster. | Approved |
| FR-202 | Receive automated shift alarms and inspection reminders. | Approved |
| FR-203 | Start an inspection round for a location during an active shift. | Added |
| FR-204 | Complete and submit a digital maintenance checklist for a lab workstation. | Approved |
| FR-205 | Record a result of OK, Issue, or N/A per checklist item, with an optional remark. | Assumed — [Q2](02-open-questions.md) |
| FR-206 | Raise a workspace incident ticket for faulty hardware or a network drop. | Approved |
| FR-207 | Raise a ticket pre-filled from a failed checklist item, without re-entering the asset, location or fault. | Added |
| FR-208 | View own submitted tickets and their current status. | Added |
| FR-209 | View the live status of lab Wi-Fi access points (read-only). | Approved |
| FR-210 | Check in at shift start and check out at shift end. | Added |
| FR-211 | Record handover notes when ending a shift. | Approved |
| FR-212 | View unread notifications with a count badge. | Added |

### FR-300 — Network Engineer

| ID | Requirement | Status |
|---|---|---|
| FR-301 | View a real-time access point health and coverage dashboard. | Approved |
| FR-302 | The dashboard refreshes without a manual page reload. | Assumed — polling, ADR-0005 |
| FR-303 | View historical network traffic and congestion logs. | Approved |
| FR-304 | Drill into a single access point and view its recent telemetry as a chart. | Added |
| FR-305 | Receive automated alerts for network anomalies or offline access points. | Approved |
| FR-306 | View, filter and sort the full incident ticket queue. | Approved |
| FR-307 | Acknowledge, assign, progress, resolve and close tickets. | Assumed — [Q3](02-open-questions.md) · **complete 2026-09-02.** Every verb but *assign* was built; ownership was implicit, so a ticket picked up by the wrong person could only be moved in the database. Assignment is deliberately **not** a transition — who is doing the work and how far along it is are different questions. |
| FR-308 | Add comments to a ticket, optionally internal-only. | Added |
| FR-309 | View assets ranked by predictive risk score. | Added — **built 2026-08-30.** Lab PCs and access points in one queue, ranked on the same 0-100 scale. |
| FR-310 | View the contributing factors behind any asset's risk score. | Added — **built 2026-08-30.** Stored as JSON at the time of scoring, so an old score still explains itself after the weights change. |
| FR-311 | Raise a preventive ticket directly from a high-risk asset. | Added — **built 2026-08-30.** Pre-filled with the actual factors, asset locked. The only route by which an access point can be ticketed through the form. |

### FR-400 — System Administrator

| ID | Requirement | Status |
|---|---|---|
| FR-401 | Create, edit, deactivate and reactivate user accounts. | Approved |
| FR-402 | Assign and change a user's role. | Approved |
| FR-403 | Create and maintain campus locations. | Added |
| FR-404 | Add, update and retire lab PC hardware inventory records. | Approved |
| FR-405 | Add, update and retire access point inventory records. | Approved |
| FR-406 | Create and version checklist templates and their items. | Assumed — [Q2](02-open-questions.md) |
| FR-407 | Configure recurring shift templates. | Approved |
| FR-408 | Generate dated shifts from templates and override individual assignments. | Assumed — [Q4](02-open-questions.md) · **both halves built.** Generation in M6; the override built 2026-09-02, from the roster screen. Scheduled shifts only — a checked-in one has somebody in the room and `checked_in_at` says who. |
| FR-409 | View a system audit log of significant actions. | Approved |
| FR-410 | Generate and view operational reports and descriptive statistics. | Approved — **built 2026-08-30.** Five reports. Admin and engineer. |

### FR-500 — System behaviour (no direct user action)

| ID | Requirement | Status |
|---|---|---|
| FR-501 |
 Collect one telemetry reading per active access point every minute. | Added |
| FR-502 | Classify an access point as degraded or offline according to configured thresholds. | Assumed — [Q1](02-open-questions.md) |
| FR-503 | Automatically raise a ticket when an access point goes offline. | Added |
| FR-504 | Aggregate raw telemetry into hourly summaries. | Assumed — [Q9](02-open-questions.md) |
| FR-505 | Recalculate risk scores for all assets daily, retaining history. | Added — **built 2026-08-30.** `atlas:recalculate-risk`, scheduled 02:00. One timestamp per run, so a run can be selected as a unit. |
| FR-506 | Dispatch due alarms every minute. | Added |
| FR-507 | Raise an alarm when a ticket passes its SLA due time. | Assumed — [Q3](02-open-questions.md) |
| FR-508 | Prune raw telemetry older than the configured retention period. | Assumed — [Q10](02-open-questions.md) |
| FR-509 | Record an audit log entry for every create, update and delete of a user, asset or configuration record. | Added |

---

## Non-functional requirements

Two of these come from the Meeting 2 minutes recorded in the proposal appendix but appear nowhere in the proposal body. They belong in the final report.

| ID | Requirement | Target | Source |
|---|---|---|---|
| NFR-01 | **Availability** — annual downtime | < 30 minutes | Meeting 2 minutes, 07/08/2026 |
| NFR-02 | **Response time** — page and API response under normal load | < 7 seconds | Meeting 2 minutes, 07/08/2026 |
| NFR-03 | **Security** — privilege-based access; no public or student-facing entry point | All routes behind auth + role middleware | Proposal Keynote, §1.5.1 |
| NFR-04 | **Security** — passwords stored hashed, never in plain text | Laravel default hashing | Added |
| NFR-05 | **Usability** — the inspection checklist is fully operable on a mobile phone one-handed | Tested at 375 px width | Proposal §1.4.2 |
| NFR-06 | **Auditability** — every significant state change is attributable to a user and a timestamp | `audit_logs` table | Proposal §Admin Module |
| NFR-07 | **Maintainability** — all business thresholds configurable without code change | `config/atlas.php` | ADR-0002 |
| NFR-08 | **Data integrity** — referential integrity enforced at database level, not only in application code | 24 foreign key constraints, one on every single-target relationship. The four polymorphic ones (`tickets.ticketable`, `alarms.related`, `risk_assessments.assessable`, `audit_logs.auditable`) **cannot carry one** — a column pointing at two tables has nothing to reference. Those are guarded in the application instead: the morph type is whitelisted in the Form Request, and assets are soft-deleted rather than removed. | Added |
| NFR-09 | **Compatibility** — runs on the current stable Chrome, Edge and Firefox | Manual check during UAT | Added |
| NFR-10 | **Accessibility** — all interactive elements keyboard-reachable with a visible focus state | Manual check during UAT | Added |

> NFR-01 and NFR-02 are **test criteria**, not aspirations. They appear as pass conditions in [07-testing.md](07-testing.md).

---

## Traceability

| Module | Requirements |
|---|---|
| M1 — Identity and audit | FR-101 to FR-106, FR-401, FR-402, FR-409, FR-509 |
| M2 — Locations and assets | FR-403, FR-404, FR-405 |
| M3 — Checklists and inspections | FR-203, FR-204, FR-205, FR-406 |
| M4 — Ticketing | FR-206, FR-207, FR-208, FR-306, FR-307, FR-308, FR-503, FR-507 |
| M5 — Telemetry and dashboard | FR-209, FR-301 to FR-305, FR-501, FR-502, FR-504, FR-508 |
| M6 — Shifts and alarms | FR-201, FR-202, FR-210, FR-211, FR-212, FR-407, FR-408, FR-506 |
| M7 — Predictive and reports | FR-309, FR-310, FR-311, FR-410, FR-505 |
