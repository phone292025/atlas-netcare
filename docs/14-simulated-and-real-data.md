# What Is Simulated, and What Would Make It Real

> **Last updated:** 2026-08-30
>
> **This file is written to be lifted into the report.** Section 1 is the Limitations section. Section 2 is the Assumptions section. Section 3 is what to say if a marker asks "is any of this real?"
>
> Every figure here was read out of the live database on 2026-08-30, not estimated.

---

## The short answer

**One thing is simulated: access point telemetry.** Everything else is either real, or is demonstration data standing in for a record somebody already keeps.

That distinction matters because the two need different answers in a viva. Simulated telemetry is a *design decision* with an architecture behind it. Fabricated asset tags are *seed data*, and replacing them is a spreadsheet, not a permission.

---

## 1. For the Limitations section

### 1.1 Access point telemetry is generated, not measured

**24,585 readings and 1,365 hourly rollups, every one tagged `source = 'simulated'`.**

`SimulatedTelemetrySource` produces latency, packet loss, client counts and uptime from a baseline popularity per access point, multiplied by a time-of-day curve and a weekday factor, with bounded jitter on top. It is deliberately not `rand()`: ADR-0002 argued that random noise uncorrelated with anything would make every chart meaningless and every risk trend an artefact.

**Why it is simulated.** Reading real access points needs SNMP access to campus network equipment, which is a permission granted by APU IT to a student project. We do not control that decision and may never receive it. The system was built so the headline feature does not depend on it.

**What replacing it costs.** One class. `SnmpTelemetrySource` is a deliberate stub that throws with instructions; implement `collect()`, set `ATLAS_TELEMETRY_DRIVER=snmp`, and nothing else changes — no controller, no view, no migration, no test. The interface is the whole point of ADR-0002, and being able to say "going live is one class" is a stronger answer than having live data would have been.

**State this plainly in the report.** A marker who discovers simulated telemetry unannounced reads it as a gap. A marker told up front that it is simulated *behind a swappable interface, for a stated reason* reads it as design.

### 1.2 Alarms reach the notification bell and nothing else

In-app only. The one exception is shift reminders, which leave the system as a calendar file and are then handled by the phone's own alarm, working with the app closed. Unplanned events — an access point dropping — reach the bell only.

Email is no longer a limitation: FR-102 sends password resets through Gmail SMTP and was confirmed arriving on a phone on 2026-08-29. Alarms *could* be emailed now. That is a decision nobody has taken, not a thing that cannot be done.

### 1.3 Operational history is thin, because it is genuine

3 tickets, 2 completed inspections, 3 shifts, 1 shift template. These are **not** simulated — they are real records created by using the system. There are simply very few of them, which is why mean time to resolution is computed from two tickets.

Thin real data is more defensible than thick fake data, but the reports do look sparse. Say which it is.

### 1.4 No asset scores High or Critical

Every seeded asset lands Low or Medium. The scoring is correct; the demonstration data has no old machines and no degrading links, so there is nothing dramatic to score.

**This is worth fixing before the demonstration.** A maintenance priority ranking with no Critical row does not show what the screen is for.

---

## 2. For the Assumptions section

Every number below is the team's engineering judgement. **None was derived from measurement**, and ADR-0007 commits to saying so.

### 2.1 What counts as degraded

| Setting | Value | Meaning |
|---|---|---|
| `thresholds.latency_ms` | 120 | Above this, an access point is degraded |
| `thresholds.packet_loss_pct` | 5 | Above this, degraded |
| `thresholds.client_saturation` | 0.85 | Above 85% of capacity, degraded |
| `thresholds.consecutive_breach` | 3 | Readings in a row before the state flips |
| `thresholds.offline_after_min` | 5 | Silence before an AP counts as offline |

The three-reading rule exists so one noisy sample cannot flip a state. That part is defensible on its own reasoning; the numbers are not.

### 2.2 How risk is weighted

| Lab PC factor | Weight | | Access point factor | Weight |
|---|---|---|---|---|
| Fault frequency | 0.30 | | Latency trend | 0.30 |
| Age | 0.25 | | Offline events | 0.30 |
| Critical failure rate | 0.25 | | Packet loss trend | 0.25 |
| Inspection lag | 0.20 | | Client saturation | 0.15 |

Each set sums to 1.0, and a unit test enforces that per set — if they drift, every score in that set silently scales wrong and nothing raises an error.

### 2.3 The denominators

| Setting | Value | Assumption it encodes |
|---|---|---|
| `pc_life_years` | 6 | A lab PC's expected service life |
| `fault_ceiling` | 10 | Ten tickets in 90 days is as bad as the fault factor goes |
| `inspect_ceiling_days` | 30 | A month uninspected is a full-weight concern |
| `trend_days` | 14 | Long enough that one bad evening does not move it |
| `risk_bands` | 0 / 40 / 65 / 85 | Low, Medium, High, Critical cutoffs |
| `sla_hours` | 4 / 24 / 72 | Critical, Medium, Low ticket targets |

### 2.4 Reference data that is genuinely real

**The seven locations are real** and match the three zones named in the approved proposal: the IT Labs, the Library and the Campus Cafeteria. `L6-IT-01`, `LIB-L3`, `CAF-GF` and the rest are not invented.

**The 13 checklist items are our own procedure, not APU's.** They are sensible — "Machine powers on and reaches the desktop" is marked critical, "No flicker, lines or dead pixels" is not — but nobody at APU ratified them. Q2 settled how checklists work, not what is on them.

---

## 3. Demonstration data, and what would replace it

Everything here is tagged `is_demo` and removable. None of it is a design limitation; each is a record somebody already keeps.

| What | Rows | What is fabricated | What would replace it |
|---|---|---|---|
| Access points | 5 | MAC addresses, IPs, models, client capacities | The AP inventory from IT |
| Lab PCs | 9 (8 demo) | Asset tags, hostnames, **purchase dates** | The IT asset register |
| Accounts | 5 (4 demo) | Nothing much — real names, real deliverable addresses | Nothing; these are fine |

**Purchase date is the one that matters.** It is 25% of every lab PC's risk score, and it is currently invented. A machine's age is the single most defensible input the model has, and right now it is the least real.

### The two worth actually pursuing

**1. The lab PC asset register.** A spreadsheet from IT. No network permission, no security review, no SNMP. It makes every lab PC risk score defensible instead of derived from invented dates — and it fixes the "no Critical rows" problem for free, because real machines have real ages and some of them are old.

**2. One latency measurement from campus wifi.** A single `ping` against a real APU access point. It turns `thresholds.latency_ms = 120` from a guess into a checked number. Q1 has had this as an open action since Sprint 0. It costs five minutes and it is exactly the kind of thing a viva asks about.

Neither depends on a permission anybody has to grant.

---

## 4. How to answer "is any of this real?"

> The locations are real and match the proposal's scope. The accounts are real people on real addresses. The tickets, inspections and shifts are genuine records created by using the system — there are just few of them.
>
> Access point telemetry is simulated, deliberately, because reading live campus equipment needs a permission we do not control. It sits behind a `TelemetrySource` interface with a second implementation already stubbed, so going live is one class and a configuration change — no controllers, views, migrations or tests.
>
> The asset inventory is demonstration data standing in for IT's register. The thresholds and risk weights are our engineering judgement rather than measured values, which is stated in the Assumptions section.

---

## Related

- [ADR-0002](decisions/ADR-0002-telemetry-source-driver.md) — why telemetry is behind an interface
- [ADR-0003](decisions/ADR-0003-single-database-demo-flag.md) — how demonstration data is tagged and removed
- [ADR-0007](decisions/ADR-0007-weighted-risk-not-ml.md) — why weights rather than a trained model
- [Q1](04-open-questions.md#q1) — the degraded thresholds, and the sanity check still outstanding
- [07-data-strategy.md](07-data-strategy.md) — the mechanisms behind `is_demo` and `source`
