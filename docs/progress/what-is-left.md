# What Is Left

> **Last updated:** 2026-08-30 · **Written as the handover into the review session planned for Wednesday.**
>
> Every line below was checked against the code and the live database on 2026-08-30, not recalled. Where something is missing, the check that found it is named so it can be re-run.

---

## The plan this file supports

1. **Fix what is known to be missing** — the list in section 1.
2. **Settle the real-data question** — section 2. If SNMP access ever arrives, make sure the swap actually works rather than assuming it does.
3. **Review M1 to M7 feature by feature**, file by file, finding and fixing bugs live — section 3 is the running order and what to watch for.

---

## 1. Genuinely not built

Four things. Nothing else in the code is missing.

### 1.1 FR-408 — override an individual shift assignment

**Status:** the "generate dated shifts from templates" half is built; the "override individual assignments" half is not.

M6 built `ShiftService::generateWeek()`, which materialises next week's roster from templates. There is no way to reassign one shift to a different person without editing the database.

**Why it matters:** it is a functional requirement in the approved proposal, currently marked `Assumed — Q4`. It is also the last functional gap in the entire system.

**Size:** small. A form on the roster screen, a service method that writes `user_id` on one shift, a policy check, and tests. The shift already copies its times from the template rather than reading through it, so overriding one does not disturb the pattern.

### 1.2 `atlas:purge-demo-data` does not exist

**Referenced in six places** — README twice, `docs/07-data-strategy.md`, `docs/06-database.md`, `ADR-0003`, and the `DemoDataSeeder` docblock. Running it fails.

Every reference now carries a warning saying so, but the honest fix is to write it. The `is_demo` flags and `source = 'simulated'` markers it needs are all present and correctly populated:

| Table | Rows | Tagged |
|---|---|---|
| `users` | 5 | 4 `is_demo` |
| `lab_pcs` | 9 | 8 `is_demo` |
| `access_points` | 5 | 5 `is_demo` |
| `telemetry_readings` | 24,585 | 24,585 `source = simulated` |
| `telemetry_hourly` | 1,365 | 1,365 `source = simulated` |
| `tickets` | 3 | **0 `is_demo`** |

**Note the last row.** The three tickets are real records created by using the system, so a purge would correctly leave them. That is right, but it means the command cannot be tested against tickets without seeding some first.

**Size:** medium. Chunked deletes, a `--dry-run`, a confirmation unless `--force`. Follow `PruneTelemetry` for the shape.

**Re-run the check:** compare `grep -rho "atlas:[a-z-]*" docs/ README.md | sort -u` against `ls app/Console/Commands/`.

### 1.3 `DemoDataSeeder` creates accounts and nothing else

Its own docblock promises ~250 lab PCs, ~40 access points, completed inspections, tickets across every state, 90 days of telemetry, and shift templates — "arriving with M2 to M6". They never arrived. It prints that message on every run.

Everything currently in the database was created by hand or by ad-hoc commands, which is why there are 9 lab PCs rather than 250.

**Size:** medium, and it depends on 1.4.

### 1.4 No asset scores High or Critical

```
low         7  #######
medium      7  #######
high        0
critical    0
```

The scoring is correct. The seeded assets are neither old nor degrading, so there is nothing dramatic to score.

**Why it matters more than it looks:** a maintenance priority ranking with no red rows does not demonstrate what the screen is for, and M7 is the module that makes this project answer Case Study Category 4. This is the single most visible weakness in a live demonstration.

**Size:** small if done directly — a handful of lab PCs with purchase dates five or six years back, and one access point with a deteriorating latency series. Better done as part of 1.3 so it is reproducible rather than hand-typed.

---

## 2. The real-data question

Full detail is in [../14-simulated-and-real-data.md](../14-simulated-and-real-data.md). What matters for the review session:

### 2.1 If SNMP access arrives, this is the test

`SnmpTelemetrySource` is a deliberate stub that throws with instructions. The claim the whole architecture rests on is: **implement `collect()`, set `ATLAS_TELEMETRY_DRIVER=snmp`, and nothing else changes.**

That claim has never been exercised. It is very likely true — the binding is one line in `AppServiceProvider` and everything downstream depends on the interface — but "very likely true" is not the same as tested.

**Worth doing even without real access:** write a second fake implementation of `TelemetrySource` under a different driver name, point `ATLAS_TELEMETRY_DRIVER` at it, and confirm the dashboard, the charts, the alarms and the risk trends all still work. That proves the swap without needing APU IT, and it is a much stronger thing to say in a viva than "it should work".

**If real access does arrive,** the stub's docblock already lists what to get right: credentials in `.env`, one `ReadingData` per access point, `ReadingData::unreachable()` for an AP that does not answer rather than zeros, no health classification in the driver, and respect `AccessPoint::scopeCollectable()` so an AP under maintenance is not polled.

### 2.2 The two real-data wins that need nobody's permission

**The lab PC asset register.** A spreadsheet from IT. Purchase date is 25% of every lab PC's risk score and is currently invented. Real ages would also fix 1.4 for free.

**One latency measurement from campus wifi.** Turns `thresholds.latency_ms = 120` from a guess into a checked number. Q1 has had this open since Sprint 0. Five minutes.

---

## 3. The M1 to M7 review — suggested running order

The audit above covers what is *missing*. The review is about what is *wrong*, which is a different question and needs the system exercised rather than read.

**Go in dependency order**, because a bug in M1 shows up as a symptom in M4:

| Order | Module | Exercise as | Watch for |
|---|---|---|---|
| 1 | M1 identity | all three roles | Deactivated users, forced password change, audit rows appearing |
| 2 | M2 assets | admin | Retire and restore, soft-deleted records still reachable |
| 3 | M3 inspections | assistant | Empty inspection form, template versioning after an edit |
| 4 | M4 ticketing | assistant then engineer | Illegal transitions refused, comment visibility |
| 5 | M5 telemetry | engineer | Charts with no data, filters that exclude everything |
| 6 | M6 shifts | assistant | Check-in window boundaries, calendar export |
| 7 | M7 risk | engineer, admin | Empty states, the numbers actually adding up |

### What the last three sessions suggest to look for

Every bug found recently fell into one of four shapes, and none was caught by the suite:

**Empty states.** Three 500s in M3 came from screens nobody had loaded with no data. Open every screen with an empty database as well as a populated one.

**Numbers, not labels.** M7's report showed a grid of zeroes under a heading saying "3 tickets". The tests asserted the heading. **Read the actual numbers on every screen and check they add up.**

**MariaDB versus SQLite.** Four defects so far. `php artisan atlas:check-schema` now audits both schema and data — run it at the start and end of the session.

**Anything with a date or a timezone.** The 8-hour shift bug, the corrupted `opened_at`, the calendar `TZID`. Every one was a date handled without an explicit zone.

### Before starting

```bash
php artisan test
php artisan atlas:check-schema
php artisan atlas:recalculate-risk
```

All three should be clean. If they are not, that is the first bug.

---

## 4. Not code, and larger than all of the above

**Nobody owns any module.** The code is finished; 21 module diagrams (a DFD 1, a data dictionary and a flowchart each) and three whole-system diagrams are not started. The Context Diagram, DFD 0 and consolidated ERD are **worth 15 marks between them** and have no owner.

That is now the largest single block of marks outstanding, and it is the one thing that cannot be fixed in a review session.
