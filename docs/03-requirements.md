# Requirements

> **Last updated:** 2026-08-26 · **Source:** approved proposal, section 1.3–1.5, extended by design decisions

Every requirement has an ID. Test cases reference these IDs; module specs list the IDs they satisfy. A requirement without an ID cannot be traced, so it cannot be marked.

**Status key:** `Approved` (in the submitted proposal) · `Added` (design phase, needs supervisor awareness) · `Assumed` (depends on an open question)

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
- Any software agent installed on lab PCs (see [Q7](04-open-questions.md))

---

## Functional requirements

### FR-100 — Authentication and access (all roles)

| ID | Requirement | Status |
|---|---|---|
| FR-101 | A user can sign in with an email address and password. | Approved |
| FR-102 | A user can request a password reset link by email. | Approved |
| FR-103 | A user can sign out. | Approved |
| FR-104 | The system denies access to any route not permitted for the user's role. | Approved |
| FR-105 | Self-registration does not exist. Accounts are created by administrators only. | Assumed — [Q6](04-open-questions.md) |
| FR-106 | A deactivated user cannot sign in but their historical records remain intact. | Added |

### FR-200 — Student IT Assistant

| ID | Requirement | Status |
|---|---|---|
| FR-201 | View own daily shift roster. | Approved |
| FR-202 | Receive automated shift alarms and inspection reminders. | Approved |
| FR-203 | Start an inspection round for a location during an active shift. | Added |
| FR-204 | Complete and submit a digital maintenance checklist for a lab workstation. | Approved |
| FR-205 | Record a result of OK, Issue, or N/A per checklist item, with an optional remark. | Assumed — [Q2](04-open-questions.md) |
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
| FR-307 | Acknowledge, assign, progress, resolve and close tickets. | Assumed — [Q3](04-open-questions.md) |
| FR-308 | Add comments to a ticket, optionally internal-only. | Added |
| FR-309 | View assets ranked by predictive risk score. | Added |
| FR-310 | View the contributing factors behind any asset's risk score. | Added |
| FR-311 | Raise a preventive ticket directly from a high-risk asset. | Added |

### FR-400 — System Administrator

| ID | Requirement | Status |
|---|---|---|
| FR-401 | Create, edit, deactivate and reactivate user accounts. | Approved |
| FR-402 | Assign and change a user's role. | Approved |
| FR-403 | Create and maintain campus locations. | Added |
| FR-404 | Add, update and retire lab PC hardware inventory records. | Approved |
| FR-405 | Add, update and retire access point inventory records. | Approved |
| FR-406 | Create and version checklist templates and their items. | Assumed — [Q2](04-open-questions.md) |
| FR-407 | Configure recurring shift templates. | Approved |
| FR-408 | Generate dated shifts from templates and override individual assignments. | Assumed — [Q4](04-open-questions.md) |
| FR-409 | View a system audit log of significant actions. | Approved |
| FR-410 | Generate and view operational reports and descriptive statistics. | Approved |

### FR-500 — System behaviour (no direct user action)

| ID | Requirement | Status |
|---|---|---|
| FR-501 | Collect one telemetry reading per active access point every minute. | Added |
| FR-502 | Classify an access point as degraded or offline according to configured thresholds. | Assumed — [Q1](04-open-questions.md) |
| FR-503 | Automatically raise a ticket when an access point goes offline. | Added |
| FR-504 | Aggregate raw telemetry into hourly summaries. | Assumed — [Q9](04-open-questions.md) |
| FR-505 | Recalculate risk scores for all assets daily, retaining history. | Added |
| FR-506 | Dispatch due alarms every minute. | Added |
| FR-507 | Raise an alarm when a ticket passes its SLA due time. | Assumed — [Q3](04-open-questions.md) |
| FR-508 | Prune raw telemetry older than the configured retention period. | Assumed — [Q10](04-open-questions.md) |
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
| NFR-08 | **Data integrity** — referential integrity enforced at database level, not only in application code | Foreign key constraints on every relationship | Added |
| NFR-09 | **Compatibility** — runs on the current stable Chrome, Edge and Firefox | Manual check during UAT | Added |
| NFR-10 | **Accessibility** — all interactive elements keyboard-reachable with a visible focus state | Manual check during UAT | Added |

> NFR-01 and NFR-02 are **test criteria**, not aspirations. They appear as pass conditions in [10-testing.md](10-testing.md).

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
