# Decision Log

> **Last updated:** 2026-08-27

Small calls that affect other people but do not warrant a full ADR. One line each. Newest at the top.

**Write an entry when:** you rename something shared, change a shared file, choose between two equally reasonable implementations, or discover something that changes how someone else should work.

**Write an [ADR](../decisions/) instead when:** the decision shapes the architecture, adds a dependency, or someone will later ask "why is it like this?"

| Date | Decision | Made by | Affects |
|---|---|---|---|
| 2026-08-27 | `EnsureUserHasRole` uses `! ($user->is_active ?? true)`, not `=== false`. An uncast boolean column returns `int(0)` from Eloquent, so a strict comparison never matches and the guard fails **open**. A missing attribute counts as active. Still add the `boolean` cast to `User` in Stage 4. | Stage 2 review | M1 |
| 2026-08-27 | No `pint.json`. An `align_single_space_minimal` override was tried and rejected — it forces arrow alignment across the whole codebase including Laravel's stock files. Use the default Laravel preset; do not align `=>` in config files. Run `vendor/bin/pint` before committing. | Stage 2 review | Everyone |
| 2026-08-27 | `telemetry_readings.status` stays three-valued and must **not** be cast to `ApStatus`, which also has `maintenance`. A reading records measured state; maintenance is an administrative state on the access point itself. M5 needs a narrower enum, or no cast at all. | Stage 2 review | M5 |
| 2026-08-27 | `UserRole::homeRoute()` added — admin lands on `admin.users.index`, engineer on `engineer.dashboard`, assistant on `assistant.shifts.today`. Nothing in the docs specified post-login landing pages. Revisit when `DashboardRedirect` is built in Stage 3. | Stage 2 | M1, Stage 3 |
| 2026-08-27 | Tests use PHPUnit attributes (`#[DataProvider]`), never doc-comment annotations. The doc-comment form is deprecated in PHPUnit 11 and removed in 12. | Stage 2 review | Everyone |
| 2026-08-27 | Q3 unresolved: `TicketStatus` cases and `atlas.sla_hours` written from values already documented in `06-database.md` / `05-architecture.md`, marked provisional in code comments. No lifecycle behaviour — state machine, transition guards, SLA breach logic — until Q3 is `Decided`. | Sprint 0 | M4, M6 |
| 2026-08-27 | Q6 partially directed: `register` route and view removed at Breeze install. `users.must_change_password` column created per `06-database.md`, but no first-login enforcement middleware until Q6 is `Decided`. | Sprint 0 | M1 |
| 2026-08-26 | Documentation workspace created; `docs/` is the source of truth, not conversation history. | Group | Everyone |
| 2026-08-26 | Surname spelled **Suthan**, not "Shuthan", in all documents from here on. | Group | Report, Workload Matrix |
| 2026-08-26 | Predictive risk module (M7) added as a core module to align the project with Case Study Category 4. | Group | M7, report, scope |
