# What Is Simulated, and What Would Make It Real

> **Last updated:** 2026-11-01
>
> Section 1 lists what the data stops the system from doing. Section 2 lists the numbers that are judgement rather than measurement. Section 4 answers "is any of this real?"
>
> Section 3 describes what `DemoDataSeeder` produces on a fresh install, counted from the seeder's code. Section 1.3 was read out of the development database on 2026-09-02.

---

## The short answer

**One thing is simulated: network telemetry.** Everything else is either real, or is demonstration data standing in for a record somebody already keeps.

That distinction matters because the two need different answers. Simulated telemetry is a *design decision* with an architecture behind it. Fabricated asset tags are *seed data*, and replacing them is a spreadsheet, not a permission.

---

## 1. Limitations

### 1.1 Network telemetry is generated, not measured

**Every demo device is watched by the simulator, and every reading it writes has `source = 'simulated'`.** A fresh demo install starts with about 97,000 readings, one every five minutes for 14 days on each of the 24 access points.

`SimulatedReadings` produces latency, packet loss, client counts and uptime from a baseline popularity per access point, multiplied by a time-of-day curve and a weekday factor, with bounded jitter on top. It is deliberately not `rand()`. Random noise uncorrelated with anything would make every chart meaningless and every risk trend an artefact.

**Why it is simulated.** The capstone could not count on access to APU's network equipment, so the system was built to work without it. In 2.0 a probe agent on the network measures real devices, which needs no permission beyond a machine on that network.

**What replacing it costs.** No code in Atlas. The simulator is a virtual agent (ADR-0010). It watches the demo devices through `network_devices.agent_id` and sends their readings through `TelemetryService::record()`, the same way in as a probe agent's batches through the ingest API. Going live is registering a probe agent, running it on a machine inside the network, and setting **Watched by** on each device it should measure. Nothing in a controller, view or migration knows which agent sent a reading. The missing piece is the agent program itself, roadmap task 1.7.

### 1.2 Alarms reach the notification bell and nothing else

In-app only. The one exception is shift reminders, which leave the system as a calendar file and are then handled by the phone's own alarm, working with the app closed. Unplanned events, such as a device dropping or an agent going silent, reach the bell only.

Email is no longer a limitation: FR-102 sends password resets through whichever SMTP server `.env` names, and was confirmed arriving on a phone on 2026-08-29. Out of the box mail goes to the log. Alarms *could* be emailed. That is a decision nobody has taken, not a thing that cannot be done.

### 1.3 Operational history is thin, because it is genuine

3 tickets, 2 completed inspections, 3 shifts, 1 shift template. These are **not** simulated — they are real records created by using the system. There are simply very few of them, which is why mean time to resolution is computed from two tickets.

Thin real data is more defensible than thick fake data, but the reports do look sparse.

### 1.4 ~~No asset scores High or Critical~~ — fixed 2026-09-02

`DemoDataSeeder` now builds four deliberate age cohorts and three deteriorating access points, so all four bands populate. Five machines score 92, and the ranking's top rows give their reasons as "age, fault frequency".

**The cohorts are designed.** They are not tuned to make the scoring look good; they are the kind of asset the scoring exists to find — a machine nearly seven years old, never inspected, with eight repairs in three months. That is a fair description of something that should have been replaced, and the model finding it is the point.


---

## 2. Assumptions

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

**The six locations** match the three zones named in the approved proposal: the IT Labs, the Library and the Campus Cafeteria. `L6-IT-01`, `LIB-L3`, `CAF-GF` and the rest are not invented.

**The 13 checklist items are our own procedure, not APU's.** They are sensible — "Machine powers on and reaches the desktop" is marked critical, "No flicker, lines or dead pixels" is not — but nobody at APU ratified them. Q2 settled how checklists work, not what is on them.

---

## 3. Demonstration data, and what would replace it

Everything here is tagged `is_demo` and removable. None of it is a design limitation; each is a record somebody already keeps.

Counts are what `DemoDataSeeder` produces on a fresh install.

| What | Rows | What is fabricated | What would replace it |
|---|---|---|---|
| Access points | 24 | MAC addresses, IPs, models, client capacities | The AP inventory from IT |
| Router and switches | 7: one core router, one switch per location | Codes, IPs, models | The network inventory from IT |
| Lab PCs | 80 | Asset tags, hostnames, **purchase dates** | The IT asset register |
| Telemetry readings | about 97,000, every 5 minutes for 14 days | All of it | Readings from a probe agent |
| Inspections | 25 | Outcomes and remarks | Nothing; real ones accumulate by using the system |
| Tickets | 43 | Titles and timings | Same |
| Accounts | 4, one per role plus a second assistant | Names, and addresses on reserved domains (`@apu.test`, `@atlas.local`) that can never receive mail | Accounts an administrator creates. In production the seeder makes no administrator; `atlas:create-admin` does |

**Purchase date is the one that matters.** It is 25% of every lab PC's risk score, and it is currently invented. A machine's age is the single most defensible input the model has, and right now it is the least real.

### The two worth actually pursuing

**1. The lab PC asset register.** A spreadsheet from IT. No network permission and no security review. It is the difference between a risk model scored on ages we chose and one scored on ages the campus actually has — and age is 25% of every lab PC's score, so it is the single largest fabricated input in the system.

**2. One latency measurement from campus wifi.** A single `ping` against a real APU access point. It turns `thresholds.latency_ms = 120` from a guess into a checked number. Q1 has had this as an open action since the first week of the build. It costs five minutes.

Neither depends on a permission anybody has to grant.

---

## 4. How to answer "is any of this real?"

> The locations match the proposal's scope. The demo accounts are sample sign-ins, one per role, on addresses that can never receive mail. On a fresh install the tickets and inspections are demonstration data too, tagged and removable. On a database people have used, the records they created are genuine.
>
> Network telemetry is simulated in the demo. The simulator is an agent like the real ones, and its readings take the same path into the database as a probe agent's. Going live means running a probe agent and pointing devices at it, with no change to controllers, views, migrations or tests.
>
> The asset inventory is demonstration data standing in for IT's register. The thresholds and risk weights are engineering judgement rather than measured values.

---

## Related

- [ADR-0010](decisions/ADR-0010-probe-agents-push-readings.md): probe agents, and the simulator as one of them. It superseded ADR-0002
- [ADR-0003](decisions/ADR-0003-single-database-demo-flag.md) — how demonstration data is tagged and removed
- [ADR-0007](decisions/ADR-0007-weighted-risk-not-ml.md) — why weights rather than a trained model
- [Q1](02-open-questions.md#q1) — the degraded thresholds, and the sanity check still outstanding
- [05-data-strategy.md](05-data-strategy.md) — the mechanisms behind `is_demo` and `source`
