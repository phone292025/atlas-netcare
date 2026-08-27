# Decision Log

> **Last updated:** 2026-08-27

Small calls that affect other people but do not warrant a full ADR. One line each. Newest at the top.

**Write an entry when:** you rename something shared, change a shared file, choose between two equally reasonable implementations, or discover something that changes how someone else should work.

**Write an [ADR](../decisions/) instead when:** the decision shapes the architecture, adds a dependency, or someone will later ask "why is it like this?"

| Date | Decision | Made by | Affects |
|---|---|---|---|
| 2026-08-27 | Q3 unresolved: `TicketStatus` cases and `atlas.sla_hours` written from values already documented in 06-database.md / 05-architecture.md, marked provisional in code comments. No lifecycle behaviour (state machine, transition guards, SLA breach logic) built until Q3 is `Decided`. | Sprint 0 | M4, M6 |
| 2026-08-27 | Q6 partially directed: `register` route and view removed at Breeze install. `users.must_change_password` column created per 06-database.md, but no first-login enforcement middleware built until Q6 is `Decided`. | Sprint 0 | M1 |
| 2026-08-26 | Documentation workspace created; `docs/` is the source of truth, not conversation history | Group | Everyone |
| 2026-08-26 | Surname spelled **Suthan**, not "Shuthan", in all documents from here on | Group | Report, Workload Matrix |
| 2026-08-26 | Predictive risk module (M7) added as a core module to align the project with Case Study Category 4 | Group | M7, report, scope |
