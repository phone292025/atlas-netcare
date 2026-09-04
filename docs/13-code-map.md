# Code map

> **Last updated:** 2026-09-04

Every file in the project, what it does, and how long it is. Written for
somebody who has just cloned the repository and wants to know where anything
lives before changing it.

The one-line descriptions come from each file's own docblock. They are
harvested rather than retyped, so this page cannot quietly disagree with the
code the way a hand-copied list would. Regenerate it whenever the layout
changes.

**Read [09-how-the-system-works.md](09-how-the-system-works.md) first** if you
want the story of what the system does. This page is the index, not the
narrative.

---

## How a request travels

Nothing here is clever, and that is deliberate. Seven people over eight weeks
need an architecture each of them can explain under questioning.

```
Browser
  |     Blade, Tailwind, Alpine for interaction, Chart.js for graphs
routes/
  |     grouped by role prefix
app/Http/Middleware/
  |     auth, then role. Nothing reaches a controller unverified
app/Http/Requests/
  |     is the request well formed?
app/Policies/
  |     may this person do this to this record?
app/Http/Controllers/
  |     validate, call a service, return a view. Decides nothing itself
app/Services/
  |     every rule in the system lives here
app/Models/
  |     Eloquent. Relationships, enum casts, scopes
MariaDB
```

Three separate questions get three separate files. Moving a ticket asks
whether the request is well formed (`TransitionTicketRequest`), whether this
person may do it (`TicketPolicy`), and whether the move is legal at all
(`TicketService`). Collapse any two and "an engineer closed a ticket assigned
to somebody else" becomes possible.

Scheduled commands enter at the service layer and skip the top three. A
command and a browser request run the same rules.

---

## routes/

Three files. Every URL the system answers.

| File | What it does | Lines |
|---|---|---|
| `auth.php` | _no summary in the file_ | 87 |
| `console.php` | _no summary in the file_ | 92 |
| `web.php` | _no summary in the file_ | 357 |

`web.php` is grouped by role, so an assistant's URLs sit under
`/assistant`, an engineer's under `/engineer`, and an administrator's under
`/admin`. The grouping is not decoration: the `role` middleware is applied to
the group, so a new screen inherits its protection from where you put it.

One route sits deliberately outside the `auth` group. The calendar feed is
fetched by a phone's calendar app, which cannot sign in, so it authenticates
with a 48 character token in the URL and is rate limited instead.

---

## app/Http/Controllers/

A controller validates, calls a service, and renders. It decides nothing.
Twenty-seven of them, plus the base class they extend. The longest is 236 lines.

| File | What it does | Lines |
|---|---|---|
| `Admin/AccessPointController.php` | Access point inventory (FR-405) | 130 |
| `Admin/AuditLogController.php` | The audit trail, read-only (FR-409) | 46 |
| `Admin/ChecklistTemplateController.php` | Checklist templates (FR-406, Q2) | 128 |
| `Admin/LabPcController.php` | Lab workstation inventory (FR-404) | 166 |
| `Admin/LocationController.php` | Campus places that house assets (FR-403) | 114 |
| `Admin/RosterController.php` | The week view, and the button that fills it (FR-408) | 119 |
| `Admin/ShiftTemplateController.php` | Weekly shift patterns (FR-407) | 103 |
| `Admin/UserController.php` | Account management. The only route into the system for a new user, since | 143 |
| `Api/DashboardDataController.php` | The endpoint the dashboard polls (FR-302) | 30 |
| `Assistant/AccessPointStatusController.php` | AP status, read-only, for an assistant on a lab round (FR-209) | 28 |
| `Assistant/InspectionController.php` | An assistant working down a rack of machines (FR-203, FR-204, FR-205) | 177 |
| `Assistant/ShiftController.php` | An assistant's own shift (FR-201, FR-210, FR-211) | 116 |
| `Assistant/TicketController.php` | An assistant raising and following their own tickets (FR-206, FR-207, | 237 |
| `Auth/AuthenticatedSessionController.php` | Display the login view | 48 |
| `Auth/NewPasswordController.php` | Display the password reset view | 107 |
| `Auth/PasswordController.php` | Update the user's password | 40 |
| `Auth/PasswordResetLinkController.php` | FR-102, step one: ask for a link | 96 |
| `Controller.php` | Laravel 12 ships this class empty. AuthorizesRequests is added here so every | 22 |
| `DashboardRedirect.php` | Sends a signed-in user to the home screen for their role | 44 |
| `Engineer/DashboardController.php` | The access point health dashboard (FR-301, FR-304) | 58 |
| `Engineer/RiskController.php` | Maintenance priority (FR-309, FR-310) | 179 |
| `Engineer/TicketController.php` | The engineer's queue and the transitions that move a ticket along | 165 |
| `Engineer/TrafficLogController.php` | Traffic and congestion logs (FR-303) | 112 |
| `NotificationController.php` | The notification bell and its list (FR-212) | 71 |
| `ProfileController.php` | Display the user's profile form | 39 |
| `ReportController.php` | Operational reports and descriptive statistics (FR-410) | 45 |
| `ShiftCalendarController.php` | Handing shifts to the assistant's own phone calendar | 124 |
| `TicketCommentController.php` | Comments on a ticket (FR-308) | 41 |

`ReportController` is worth a look if you want to see the rule enforced. It
was 309 lines of aggregation until the query building moved into
`ReportingService`. It is 34 lines now and every one of its eleven tests
passed unchanged, which is the only evidence that a move like that preserved
behaviour.

---

## app/Http/Middleware/

Two files, and they answer different questions.

| File | What it does | Lines |
|---|---|---|
| `EnsurePasswordIsChanged.php` | Stops a user who is still on an administrator-chosen password | 80 |
| `EnsureUserHasRole.php` | Area-level authorisation: may this role reach this part of the system at all? | 78 |

`EnsureUserHasRole` is applied per route group: may this role reach this area
at all? `EnsurePasswordIsChanged` is appended to the whole `web` group
instead, because a control you have to remember to apply is one somebody will
forget on the next screen they add.

---

## app/Http/Requests/

Form Requests. Each one validates the shape of a request and, in three cases,
authorises it. Fourteen files.

| File | What it does | Lines |
|---|---|---|
| `Admin/AccessPointRequest.php` | A Wi-Fi access point | 64 |
| `Admin/ChecklistTemplateRequest.php` | A checklist template and all of its items, in one submission | 84 |
| `Admin/LabPcRequest.php` | A lab workstation | 64 |
| `Admin/LocationRequest.php` | One request class for create and update | 52 |
| `Admin/ShiftTemplateRequest.php` | A recurring weekly slot | 38 |
| `Admin/StoreUserRequest.php` | Creating an account. There is no self-registration, so this is the only way | 44 |
| `Admin/UpdateUserRequest.php` | Editing an account | 38 |
| `Assistant/InspectionRequest.php` | A completed inspection: one answer per checklist item | 102 |
| `Auth/LoginRequest.php` | Determine if the user is authorized to make this request | 107 |
| `ProfileUpdateRequest.php` | Get the validation rules that apply to the request | 32 |
| `Tickets/AssignTicketRequest.php` | Handing a ticket to an engineer, or taking the name off it (FR-307) | 54 |
| `Tickets/StoreCommentRequest.php` | A comment on a ticket (FR-308) | 29 |
| `Tickets/StoreTicketRequest.php` | Raising a ticket | 158 |
| `Tickets/TransitionTicketRequest.php` | Moving a ticket to another state | 49 |

`StoreTicketRequest` carries the most logic, and for a reason: `ticketable_id`
means nothing without `ticketable_type`, so a rule that validated them
separately let a ticket be raised against a retired machine or an id that did
not exist. It also whitelists the morph type as `lab_pc` or `access_point`,
so a crafted POST cannot name an arbitrary class.

---

## app/Services/

Every rule in the system. If code decides something, it belongs here.

| File | What it does | Lines |
|---|---|---|
| `Maintenance/ChecklistService.php` | Creating, versioning and activating checklist templates | 156 |
| `Maintenance/InspectionService.php` | Starting, saving and finishing an inspection | 181 |
| `Reports/ReportingService.php` | The five operational reports (FR-410) | 387 |
| `Risk/RiskScoringService.php` | The predictive part of the project | 391 |
| `Shifts/AlarmService.php` | The delivery mechanism for the whole system (FR-506) | 153 |
| `Shifts/ShiftCalendar.php` | Turn shifts into an iCalendar file (RFC 5545) | 269 |
| `Shifts/ShiftService.php` | Generating the roster, and an assistant's day on it | 392 |
| `Telemetry/ReadingData.php` | One measurement, on its way from a driver into the database | 74 |
| `Telemetry/SimulatedTelemetrySource.php` | Telemetry invented, but not invented carelessly | 209 |
| `Telemetry/SnmpTelemetrySource.php` | Real access points, over SNMP | 52 |
| `Telemetry/TelemetryService.php` | Storing readings, deciding what they mean, and answering the dashboard | 370 |
| `Telemetry/TelemetrySource.php` | Where access point telemetry comes from | 37 |
| `Tickets/TicketService.php` | Opening tickets and moving them through their lifecycle | 409 |

`TelemetrySource` is the only abstraction in the project and it earns its
place. `SimulatedTelemetrySource` invents readings; `SnmpTelemetrySource`
would read real hardware. Swapping them is one line in `.env`, and no view,
controller or model knows which one answered.

`RiskScoringService` is the module the whole project turns on. It combines
weighted factors, drops any it cannot measure, and rescales the rest so a
missing input never quietly counts as zero.

---

## app/Models/

Seventeen Eloquent models. Relationships, enum casts, scopes, and the small
methods that read better on the model than in a service.

| File | What it does | Lines |
|---|---|---|
| `AccessPoint.php` | A Wi-Fi access point in one of the monitored zones | 157 |
| `Alarm.php` | Something one person needs to be told, at a particular time | 95 |
| `AuditLog.php` | One recorded fact: who changed what, when, from where | 196 |
| `ChecklistItem.php` | One question on a checklist template | 53 |
| `ChecklistTemplate.php` | A named, versioned set of checks for inspecting a lab PC | 95 |
| `Inspection.php` | One assistant completing one checklist against one lab PC | 115 |
| `InspectionResult.php` | One answer to one checklist item within one inspection | 67 |
| `LabPc.php` | A university-owned lab workstation | 155 |
| `Location.php` | A campus place that houses assets: a lab, the library, the cafeteria | 58 |
| `RiskAssessment.php` | One predictive risk score for one asset, at one moment | 105 |
| `Shift.php` | One assistant, on one date, in one place | 179 |
| `ShiftTemplate.php` | A recurring weekly slot (Q4) | 79 |
| `TelemetryHourly.php` | One hour of readings for one access point, rolled up | 57 |
| `TelemetryReading.php` | One measurement of one access point | 78 |
| `Ticket.php` | One traceable fault report | 210 |
| `TicketComment.php` | A note on a ticket (FR-308) | 66 |
| `User.php` | A person who uses Atlas NetCare | 118 |

`User` is the one to read carefully. Its `$fillable` deliberately excludes
`role`, `is_active` and `is_demo`, because a mass assigned `role` is how a
profile form becomes a privilege escalation. Its `$hidden` excludes
`calendar_token`, and the audit observer now reads that same list, so
declaring a column secret is what keeps it out of the log.

---

## app/Enums/

Twelve backed enums, plus two contracts and a trait they share. Every status column
in the database is a string cast to one of these, never a MySQL `ENUM`.

| File | What it does | Lines |
|---|---|---|
| `AlarmType.php` | Why an alarm was raised | 63 |
| `ApStatus.php` | Operational state of a Wi-Fi access point | 56 |
| `ChecklistCategory.php` | What part of a machine a checklist item is about | 60 |
| `ChecklistResult.php` | The answer to one checklist item during an inspection | 49 |
| `Concerns/HasValues.php` | Shared helpers for every backed enum in the system | 44 |
| `Contracts/HasLabel.php` | Every enum in Atlas NetCare carries its own human-readable label, so a form | 19 |
| `Contracts/HasTone.php` | A status that carries semantic colour | 20 |
| `DeviceStatus.php` | Operational state of a lab PC | 54 |
| `InspectionOutcome.php` | The overall outcome of one inspection | 68 |
| `RiskBand.php` | The band a predictive risk score falls into | 84 |
| `ShiftStatus.php` | Where a rostered shift has got to | 59 |
| `TicketCategory.php` | What kind of fault a ticket describes. Used for filtering the queue and for | 33 |
| `TicketPriority.php` | How urgent a ticket is. Drives the service level target | 63 |
| `TicketStatus.php` | Lifecycle states of a workspace incident ticket | 63 |
| `UserRole.php` | The three roles Atlas NetCare serves | 63 |

Enums return a tone, never a colour. `RiskBand::High->tone()` gives
`elevated`, and `App\Support\Tone` turns that into Tailwind classes. One file
owns the mapping, so `urgent` cannot become two different reds.

---

## app/Policies/, app/Observers/, app/Support/, app/View/Components/

The small pieces. Laravel finds all of them by convention rather than by name,
which is why a grep for the class name finds nothing and proves nothing.

| File | What it does | Lines |
|---|---|---|
| `InspectionPolicy.php` | Who may act on which inspection | 72 |
| `TicketPolicy.php` | Who may do what to a ticket | 119 |
| `UserPolicy.php` | Who may act on which account | 55 |

| File | What it does | Lines |
|---|---|---|
| `AuditObserver.php` | Writes an audit row whenever an auditable model is created, changed or | 140 |

| File | What it does | Lines |
|---|---|---|
| `FormField.php` | Shared derivations for form controls | 46 |
| `Tone.php` | The single source of truth for what a tone looks like | 186 |

| File | What it does | Lines |
|---|---|---|
| `AppLayout.php` | Get the view / contents that represents the component | 18 |
| `GuestLayout.php` | Get the view / contents that represents the component | 18 |

Policies are matched to models by name: `User` finds `UserPolicy` with no
registration anywhere. The two view components are found by tag, so
`<x-app-layout>` in 44 views resolves to `AppLayout` without the class name
appearing once.

---

## app/Providers/

One file, and more of the system passes through it than its size suggests.

| File | What it does | Lines |
|---|---|---|
| `AppServiceProvider.php` | Models whose every write is recorded in audit_logs | 168 |

Three things happen here. `AUDITED` lists every model the audit observer
watches, so adding a model to that array is how a table starts being tracked.
The `TelemetrySource` binding reads `atlas.telemetry_driver` and throws on an
unknown value rather than falling back, because a typo silently producing
invented data on a system meant to show real readings is the worst outcome
available. And `toMailUsing()` rewrites the password reset email, which is
where the on screen reset link comes from on a machine with no mail account.

---

## app/Console/Commands/

Twelve commands. Six run on a schedule, the rest are run by hand.

| File | What it does | Lines |
|---|---|---|
| `AggregateTelemetry.php` | Roll raw readings up into hourly buckets | 122 |
| `BackfillTelemetry.php` | Invent plausible history, so the charts have something to draw | 120 |
| `CheckSchema.php` | Audit the LIVE database for traps the test suite cannot see | 196 |
| `CollectTelemetry.php` | Take one reading from every access point and store it | 140 |
| `DispatchAlarms.php` | Send every alarm that has come due | 49 |
| `GenerateShifts.php` | Turn the weekly templates into dated shifts (Q4) | 57 |
| `MailTest.php` | Prove that mail actually leaves this machine | 149 |
| `PruneTelemetry.php` | Delete telemetry older than the retention window (Q10) | 58 |
| `PurgeDemoData.php` | Remove every demonstration row, leaving a system somebody could deploy | 312 |
| `RecalculateRisk.php` | Score every asset and keep the result (FR-505) | 136 |
| `RenumberTickets.php` | Give every ticket a sequential reference in the order it was opened | 267 |
| `ShowResetLink.php` | Print the most recent password reset link out of the log | 142 |

Nothing updates on its own unless `php artisan schedule:work` is running. That
is the single most common reason the dashboard looks broken when it is not.
`routes/console.php` holds the timings, with the reasoning for each next to it.

`atlas:check-schema` is the one to run after any migration. It audits the live
database for two things the test suite cannot see, because the suite runs on
SQLite: columns MariaDB rewrites on every update, and rows whose own
timestamps contradict each other.

---

## database/migrations/

Twenty-four files, one per table plus the alterations. **Never edited after
merge.** Changing a migration that has already run breaks every teammate's
database silently.

| File | What it does | Lines |
|---|---|---|
| `0001_01_01_000000_create_users_table.php` | Run the migrations | 50 |
| `0001_01_01_000001_create_cache_table.php` | Run the migrations | 36 |
| `0001_01_01_000002_create_jobs_table.php` | Run the migrations | 58 |
| `2026_08_28_070108_add_atlas_columns_to_users_table.php` | Adds the Atlas NetCare columns to Laravel's stock users table | 67 |
| `2026_08_28_070109_create_locations_table.php` | Campus places that house assets: labs, the library, the cafeteria | 44 |
| `2026_08_28_070111_create_lab_pcs_table.php` | University-owned lab workstations | 69 |
| `2026_08_28_070112_create_access_points_table.php` | Wi-Fi access points in the monitored zones | 64 |
| `2026_08_28_071545_create_audit_logs_table.php` | An attributable trail of every significant state change | 58 |
| `2026_08_28_200001_create_checklist_templates_table.php` | A named, versioned set of checks for inspecting a lab PC | 56 |
| `2026_08_28_200002_create_checklist_items_table.php` | One question on a checklist template | 54 |
| `2026_08_28_200003_create_inspections_table.php` | One assistant completing one checklist against one lab PC | 72 |
| `2026_08_28_200004_create_inspection_results_table.php` | One answer to one checklist item within one inspection | 49 |
| `2026_08_28_210001_create_tickets_table.php` | One traceable fault report, from an assistant noticing it to an engineer | 102 |
| `2026_08_28_210002_create_ticket_comments_table.php` | A note on a ticket, optionally visible only to staff (FR-308) | 50 |
| `2026_08_28_220001_create_telemetry_readings_table.php` | One measurement of one access point at one moment | 71 |
| `2026_08_28_220002_create_telemetry_hourly_table.php` | One hour of readings for one access point, rolled up | 68 |
| `2026_08_29_010001_stop_mariadb_auto_updating_domain_timestamps.php` | Stop MariaDB silently rewriting three columns that are ours to write | 85 |
| `2026_08_29_020001_create_shift_templates_table.php` | A recurring weekly slot: "Mondays 09:00-13:00, Level 6 IT Lab, 2 assistants" | 54 |
| `2026_08_29_020002_create_shifts_table.php` | One assistant, on one date, in one place | 73 |
| `2026_08_29_020003_create_alarms_table.php` | Something one person needs to be told, at a particular time | 68 |
| `2026_08_29_020004_add_shift_foreign_key_to_inspections_table.php` | Close the loop M3 left open | 38 |
| `2026_08_29_030001_add_calendar_token_to_users_table.php` | A secret per person, so their calendar app can fetch their roster | 41 |
| `2026_08_30_052620_create_risk_assessments_table.php` | One predictive risk score for one asset, at one moment | 96 |
| `2026_09_04_090001_add_previous_values_to_audit_logs_table.php` | Record what a value WAS, not only what it became | 41 |

The `add_*` migrations exist because of that rule. When `tickets.opened_at`
turned out to be a MariaDB `timestamp` that rewrote itself on every update, the
fix was a new migration, not an edit to the original.

---

## database/seeders/ and database/factories/

`ReferenceSeeder` always runs and creates what a real deployment needs.
`DemoDataSeeder` runs only while `ATLAS_DEMO_MODE=true`. Factories build rows
for tests.

| File | What it does | Lines |
|---|---|---|
| `DatabaseSeeder.php` | Two seeders, deliberately | 32 |
| `DemoDataSeeder.php` | Everything needed to demonstrate the system, and nothing the system needs to | 550 |
| `ReferenceSeeder.php` | The minimum a real deployment needs on day one | 289 |

| File | What it does | Lines |
|---|---|---|
| `AccessPointFactory.php` | Access points for tests and for M5's demo data | 45 |
| `AlarmFactory.php` | Defaults to an alarm already sent and not yet read, the state the unread | 53 |
| `ChecklistItemFactory.php` | A critical item failing turns the whole inspection into a `fail` | 40 |
| `ChecklistTemplateFactory.php` | Checklist templates for tests | 49 |
| `InspectionFactory.php` | Inspections for tests and for M7's risk-scoring input | 55 |
| `InspectionResultFactory.php` | _no summary in the file_ | 42 |
| `LabPcFactory.php` | Lab workstations for tests and for M2's demo data | 54 |
| `LocationFactory.php` | Locations are reference data, so the real ones are written out explicitly in | 31 |
| `RiskAssessmentFactory.php` | A stored risk score | 93 |
| `ShiftFactory.php` | Defaults to a shift scheduled for today that has not been started | 92 |
| `ShiftTemplateFactory.php` | _no summary in the file_ | 44 |
| `TelemetryHourlyFactory.php` | _no summary in the file_ | 32 |
| `TelemetryReadingFactory.php` | Over the latency threshold | 60 |
| `TicketCommentFactory.php` | Engineer-to-engineer. Never shown to the assistant who reported it | 34 |
| `TicketFactory.php` | Tickets for tests and demo data | 130 |
| `UserFactory.php` | The current password being used by the factory | 46 |

One database holds both kinds of row, tagged with `is_demo` rather than split
into a second schema. `atlas:purge-demo-data` removes every tagged row and
leaves real records alone.

---

## config/

Eleven files. Ten are Laravel's. One is ours.

| File | What it does | Lines |
|---|---|---|
| `app.php` | _no summary in the file_ | 127 |
| `atlas.php` | _no summary in the file_ | 463 |
| `auth.php` | _no summary in the file_ | 118 |
| `cache.php` | _no summary in the file_ | 118 |
| `database.php` | _no summary in the file_ | 185 |
| `filesystems.php` | _no summary in the file_ | 81 |
| `logging.php` | _no summary in the file_ | 133 |
| `mail.php` | _no summary in the file_ | 119 |
| `queue.php` | _no summary in the file_ | 130 |
| `services.php` | _no summary in the file_ | 39 |
| `session.php` | _no summary in the file_ | 218 |

`config/atlas.php` holds every threshold, weight, SLA hour and interval in the
system. If you are about to type a number that represents a business rule, it
goes there instead. Changing what counts as a degraded access point is a
config edit, not a code change.

---

## resources/views/

Eighty-nine Blade files. Views receive data as props and never query.

| File | Lines |
|---|---|
| `admin/access-points/_form.blade.php` | 39 |
| `admin/access-points/create.blade.php` | 21 |
| `admin/access-points/edit.blade.php` | 48 |
| `admin/access-points/index.blade.php` | 94 |
| `admin/access-points/show.blade.php` | 115 |
| `admin/audit/index.blade.php` | 77 |
| `admin/checklists/_form.blade.php` | 147 |
| `admin/checklists/create.blade.php` | 21 |
| `admin/checklists/edit.blade.php` | 74 |
| `admin/checklists/index.blade.php` | 64 |
| `admin/lab-pcs/_form.blade.php` | 32 |
| `admin/lab-pcs/create.blade.php` | 21 |
| `admin/lab-pcs/edit.blade.php` | 45 |
| `admin/lab-pcs/index.blade.php` | 82 |
| `admin/lab-pcs/show.blade.php` | 150 |
| `admin/locations/_form.blade.php` | 15 |
| `admin/locations/create.blade.php` | 18 |
| `admin/locations/edit.blade.php` | 37 |
| `admin/locations/index.blade.php` | 58 |
| `admin/roster/index.blade.php` | 98 |
| `admin/shift-templates/_form.blade.php` | 35 |
| `admin/shift-templates/create.blade.php` | 21 |
| `admin/shift-templates/edit.blade.php` | 48 |
| `admin/shift-templates/index.blade.php` | 52 |
| `admin/users/_form.blade.php` | 28 |
| `admin/users/create.blade.php` | 27 |
| `admin/users/edit.blade.php` | 42 |
| `admin/users/index.blade.php` | 93 |
| `admin/users/show.blade.php` | 113 |
| `assistant/access-points.blade.php` | 111 |
| `assistant/inspections/create.blade.php` | 63 |
| `assistant/inspections/edit.blade.php` | 103 |
| `assistant/inspections/index.blade.php` | 92 |
| `assistant/inspections/show.blade.php` | 106 |
| `assistant/shifts/index.blade.php` | 41 |
| `assistant/shifts/today.blade.php` | 272 |
| `assistant/tickets/_filters.blade.php` | 16 |
| `assistant/tickets/create.blade.php` | 101 |
| `assistant/tickets/index.blade.php` | 82 |
| `assistant/tickets/show.blade.php` | 14 |
| `auth/forgot-password.blade.php` | 82 |
| `auth/login.blade.php` | 38 |
| `auth/reset-password.blade.php` | 47 |
| `components/alert.blade.php` | 73 |
| `components/auth-session-status.blade.php` | 7 |
| `components/badge.blade.php` | 32 |
| `components/brand-mark.blade.php` | 36 |
| `components/card.blade.php` | 53 |
| `components/chart.blade.php` | 107 |
| `components/checklist-item.blade.php` | 115 |
| `components/data-table.blade.php` | 147 |
| `components/empty-state.blade.php` | 39 |
| `components/form/field.blade.php` | 58 |
| `components/form/input.blade.php` | 56 |
| `components/form/select.blade.php` | 106 |
| `components/form/textarea.blade.php` | 52 |
| `components/icon.blade.php` | 55 |
| `components/modal.blade.php` | 108 |
| `components/page-header.blade.php` | 41 |
| `components/pagination.blade.php` | 131 |
| `components/priority-pill.blade.php` | 18 |
| `components/risk-gauge.blade.php` | 82 |
| `components/sidebar.blade.php` | 58 |
| `components/stat-tile.blade.php` | 83 |
| `components/status-badge.blade.php` | 19 |
| `components/timeline.blade.php` | 86 |
| `dev/gallery.blade.php` | 242 |
| `engineer/access-point.blade.php` | 128 |
| `engineer/dashboard.blade.php` | 195 |
| `engineer/risk/index.blade.php` | 127 |
| `engineer/risk/show.blade.php` | 115 |
| `engineer/tickets/index.blade.php` | 99 |
| `engineer/tickets/show.blade.php` | 14 |
| `engineer/traffic.blade.php` | 106 |
| `errors/403.blade.php` | 10 |
| `errors/404.blade.php` | 8 |
| `errors/419.blade.php` | 21 |
| `errors/429.blade.php` | 7 |
| `errors/500.blade.php` | 8 |
| `errors/503.blade.php` | 7 |
| `errors/layout.blade.php` | 54 |
| `layouts/app.blade.php` | 172 |
| `layouts/guest.blade.php` | 46 |
| `notifications/index.blade.php` | 43 |
| `profile/edit.blade.php` | 22 |
| `profile/partials/update-password-form.blade.php` | 43 |
| `profile/partials/update-profile-information-form.blade.php` | 43 |
| `reports/index.blade.php` | 185 |
| `tickets/_detail.blade.php` | 230 |

`components/` holds 23 shared components, all of them visible at `/dev/gallery`
on a local machine. Check there before building a new one.

`assistant/inspections/edit.blade.php` is the screen the design is built
around. It is used standing up, on a phone, one handed, thirty times an hour.
Every choice button measures exactly 44 pixels, the submit bar sticks to the
bottom, and the remark box appears only when somebody taps Issue. Those are
measured facts, not intentions.

---

## tests/

Fifty-two files, 763 tests on SQLite and 764 on MariaDB. They run in about 25
seconds.

| File | What it does | Lines |
|---|---|---|
| `Feature/Admin/AccessPointInventoryTest.php` | M2, access point inventory | 244 |
| `Feature/Admin/ChecklistTemplateTest.php` | M3, checklist templates and the versioning rule (Q2) | 329 |
| `Feature/Admin/LabPcInventoryTest.php` | M2, lab PC inventory | 254 |
| `Feature/Admin/LocationManagementTest.php` | M2, locations | 169 |
| `Feature/Admin/UserManagementTest.php` | M1, account management | 538 |
| `Feature/AppShellTest.php` | The shell is the one thing every screen in the system renders inside, so a | 230 |
| `Feature/Assistant/InspectionTest.php` | M3, completing an inspection | 474 |
| `Feature/AuditReviewFixesTest.php` | Defects found in the M1, M7 review of 2026-09-02 | 417 |
| `Feature/Auth/AuthenticationTest.php` | _no summary in the file_ | 55 |
| `Feature/Auth/ForcedPasswordChangeTest.php` | Nobody uses Atlas NetCare on a password somebody else chose for them | 244 |
| `Feature/Auth/PasswordResetDeliveryTest.php` | FR-102 delivery, which is the half that was actually broken | 289 |
| `Feature/Auth/PasswordResetTest.php` | _no summary in the file_ | 74 |
| `Feature/Auth/PasswordUpdateTest.php` | _no summary in the file_ | 52 |
| `Feature/Auth/RemovedAuthFeaturesTest.php` | Breeze features this system deliberately does not have | 114 |
| `Feature/Auth/ResetWithoutMailTest.php` | Resetting a password on a machine that cannot send mail | 200 |
| `Feature/AuthFormMigrationTest.php` | The authentication and profile screens were migrated off Breeze's form | 104 |
| `Feature/ChecklistItemTest.php` | The inspection checklist is the most-used screen in the system: an assistant | 133 |
| `Feature/ComponentLibraryTest.php` | The Sprint 0 component library, Stage 3b | 293 |
| `Feature/DataInvariantsTest.php` | Damage the schema audit cannot see, and the history that explains it | 192 |
| `Feature/DemoDataSeederTest.php` | Demo accounts, one sign-in per role | 258 |
| `Feature/DevGalleryTest.php` | The component gallery is a development tool, not part of the system | 40 |
| `Feature/DisplayComponentsTest.php` | The four read-only components from Stage 3c: stat-tile, risk-gauge, timeline | 202 |
| `Feature/EnsureUserHasRoleTest.php` | Area-level authorisation is the boundary between the three roles, so it is | 201 |
| `Feature/ErrorPagesTest.php` | The error pages are screens too | 71 |
| `Feature/EveryScreenRendersTest.php` | Every screen, loaded, on a database with nothing in it | 102 |
| `Feature/ExampleTest.php` | The smallest possible check that the application boots and routes | 33 |
| `Feature/FormComponentsTest.php` | The form controls are the most reused components in the system, every admin | 159 |
| `Feature/FoundationSchemaTest.php` | The four foundation tables every other module has a foreign key into | 128 |
| `Feature/MailTestCommandTest.php` | `php artisan atlas:mail-test`, the only check that says anything real about | 98 |
| `Feature/ProfileTest.php` | Atlas NetCare is an internal, privilege-based system. Users cannot delete | 76 |
| `Feature/PurgeDemoDataTest.php` | `atlas:purge-demo-data`, turning a demonstration system into a deployable | 347 |
| `Feature/ReferenceSeederTest.php` | ReferenceSeeder is what every teammate runs on their first | 258 |
| `Feature/Risk/MaintenancePriorityTest.php` | The maintenance priority screens (FR-309, FR-310, FR-311) | 264 |
| `Feature/Risk/ReportsTest.php` | The five operational reports (FR-410) | 402 |
| `Feature/SchemaIntegrityTest.php` | Schema traps that only exist on the database we actually ship on | 93 |
| `Feature/Shifts/AlarmTest.php` | M6, alarms: the delivery mechanism for the whole system (FR-506, FR-212) | 375 |
| `Feature/Shifts/GenerateShiftsCommandTest.php` | `atlas:generate-shifts`, the command wrapper, not the service behind it | 243 |
| `Feature/Shifts/ShiftCalendarTest.php` | Handing shifts to the assistant's own phone calendar | 416 |
| `Feature/Shifts/ShiftReassignmentTest.php` | FR-408, overriding an individual shift assignment | 252 |
| `Feature/Shifts/ShiftRosterTest.php` | M6, generating the roster, and working a shift | 408 |
| `Feature/Telemetry/TelemetryClassificationTest.php` | M5, turning numbers into a health state (Q1) | 246 |
| `Feature/Telemetry/TelemetryPipelineTest.php` | M5, the driver swap, the commands, and the screens | 612 |
| `Feature/Tickets/RenumberTicketsCommandTest.php` | `atlas:renumber-tickets` repairs a database seeded before TicketFactory | 235 |
| `Feature/Tickets/TicketAccessTest.php` | M4, who may see and do what, and whether the screens render | 452 |
| `Feature/Tickets/TicketLifecycleTest.php` | M4, the ticket state machine and its SLA | 494 |
| `TestCase.php` | _no summary in the file_ | 11 |
| `Unit/AtlasConfigTest.php` | config/atlas.php is the project's single tuning surface and a shared file | 137 |
| `Unit/Enums/EnumContractTest.php` | Contract tests every enum in the system must satisfy | 141 |
| `Unit/Enums/RiskBandTest.php` | RiskBand::fromScore() decides what a maintenance priority screen shows and | 110 |
| `Unit/RiskScoringServiceTest.php` | The scoring maths, factor by factor | 364 |
| `Unit/ToneSurfacesTest.php` | Stage 3c added three more surfaces to Tone: solid(), choice() and chart() | 102 |
| `Unit/ToneTest.php` | Tone is the one file that decides what a status colour looks like anywhere in | 97 |

Two configurations run the same suite. `phpunit.xml` uses SQLite in memory
because it is fast and needs nothing installed. `phpunit.mariadb.xml` points
at a separate `atlas_netcare_test` database, because the suite truncates every
table it touches and aiming it at real data would erase it.

That second configuration exists for one reason. `SchemaIntegrityTest` skips
itself on SQLite, so the test guarding the worst bug this project has had
never ran until CI started using MariaDB.

---

## What is deliberately absent

No repository interfaces. Eloquent is already the data access layer.

No global scope hiding demo rows. A scope that hides data during a
demonstration is worse than the data being visible.

No `mark all OK` button on the checklist. Thirteen questions is thirteen taps,
and a button that answers them all at once makes it possible to submit an
inspection without looking at the machine.

No second database for demo data. One schema, tagged rows.

---

## Keeping this page honest

**This is a snapshot, taken on 2026-09-04.** Every description was lifted from
the first line of that file's own docblock, so on the day it was written it
matched the code exactly. There is no command that rebuilds it, so it will
drift as files are added and renamed. Check the date above before trusting a
line here over the file itself.

Two things follow from that. If you add a file, give it a class docblock whose
first line says what it does in one sentence, and this page can pick it up
whenever somebody regenerates it. And if you find an entry that disagrees with
the code, the code is right and this page is stale.

The one number worth re-checking is the file count. 308 files were covered
here. `find app database routes config tests -name "*.php" | wc -l` plus the
Blade count tells you whether that is still true.
