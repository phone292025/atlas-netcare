# Roadmap to Atlas 2.0

> **Last updated:** 2026-10-22 · **Current phase:** 0

Atlas 1.0 was a capstone project, and its network was simulated. 2.0 watches a real one. Small probe agents on the network measure devices and push readings to Atlas. Atlas turns failures into incidents, alerts whoever the roster says is on shift, and shows it all live. Everything can be tried on a home network first.

## What 2.0 has to be good at

1. **Alerts are right.** One real fault gives one incident and one alert. No duplicate tickets, no lost readings. Rules like "only one open incident per device" are enforced by the database, not by a check in PHP.
2. **One command runs it, and it survives restarts.** `docker compose up` starts every part, and a part that crashes restarts itself.
3. **It is live.** A fault reaches the screen within about five seconds.

The cost: one PHP process becomes several (web, queue worker, scheduler, and later a WebSocket server), plus the agent, and XAMPP is no longer enough to run it.

## Decisions

| ADR | Decision | Takes effect |
|---|---|---|
| [0010](decisions/ADR-0010-probe-agents-push-readings.md) | Probe agents push readings to an ingest API. The simulator becomes a virtual agent | Phase 1 |
| [0011](decisions/ADR-0011-postgresql-timescaledb.md) | PostgreSQL with TimescaleDB replaces MariaDB | Phase 0 (engine), Phase 1 (time-series features) |
| [0012](decisions/ADR-0012-docker-compose-stack.md) | One Docker Compose stack: app, worker, scheduler, database | Phase 0 |
| [0013](decisions/ADR-0013-probe-agent-in-go.md) | The agent is written in Go | Phase 1 |
| [0014](decisions/ADR-0014-incidents-and-domain-events.md) | Incidents between readings and tickets. Modules connected by events. The roster is the on-call schedule | Phase 2 |

Still to decide, each at the start of its phase: live updates over WebSockets (Phase 3, replacing ADR-0005), and the shape of the phone app (Phase 4).

## How the work is done

- Each task lands as small commits that each pass the checks on their own. CI on PostgreSQL decides whether a change passes.
- The app works at the end of every phase.
- Anything new that faces the outside world gets a security review before it ships: the ingest API, the Telegram bot, a public page.
- Removing comes before adding: dead code and old paths go in the same phase that replaces them.

## Phase 0: a clean base

Same features as 1.0, on a base that 2.0 can build on.

| # | Task | Status |
|---|---|---|
| 0.1 | Record the plan: ADRs 0010 to 0014 and this roadmap | Done |
| 0.2 | Remove what only the capstone needed: the `coursework/` folder, marks and rubric language, the module-ownership rule, notes about per-member diagrams. Requirements and design history stay | |
| 0.3 | Run on PostgreSQL: rewrite the MariaDB-only SQL (hourly buckets, schema checks), store emails in lower case, make searches case-insensitive, and get the whole suite green on PostgreSQL | |
| 0.4 | Docker stack: one multi-stage image and a Compose file with app, worker, scheduler and database, health checks, and migrations on start | |
| 0.5 | Create the administrator with a command, and remove the published default password | |
| 0.6 | CI: the suite on PostgreSQL with TimescaleDB, an image build, an architecture test for module boundaries, and a backup that is restored into a fresh database | |
| 0.7 | Browser tests for five core flows, run against the stack: sign in as each role, raise and resolve a ticket, submit an inspection, open the Network Monitor, print the report | |
| 0.8 | Docs: README and installation guide for Docker, the stack in `AGENTS.md`, the engine notes in `04-database.md` | |

## Phase 1: real telemetry

| # | Task |
|---|---|
| 1.1 | Devices beyond access points (switches, routers, servers), each with an optional parent device |
| 1.2 | Agents: registration, one token each, and the devices each agent watches |
| 1.3 | Ingest API: batches of readings with a per-agent sequence number, stored under a unique key. Each reading keeps the agent's time and the time Atlas received it, and drifting agent clocks are flagged. Rate limits |
| 1.4 | Readings as a TimescaleDB hypertable. Continuous aggregates replace `telemetry_hourly`. Compression after 7 days, retention by dropping chunks |
| 1.5 | The simulator as a virtual agent on the ingest path. The pull driver and the SNMP stub are removed, and ADR-0002 is superseded |
| 1.6 | Agent heartbeat: when an agent goes silent, its devices read Unknown and the agent raises an alert |
| 1.7 | Go agent, first version: config file, ping, HTTP and SNMP v2c checks run concurrently, a SQLite buffer for when Atlas is unreachable, batch sends with retries, and release builds for Windows, Linux and Raspberry Pi |
| 1.8 | Run it against a home network |

## Phase 2: incidents and on-call

| # | Task |
|---|---|
| 2.1 | Telemetry publishes domain events. An architecture test stops Telemetry importing Tickets or Shifts |
| 2.2 | Detection rules and incidents: over a limit for N readings, flapping, silent. One open incident per rule and device, enforced by a partial unique index. Incidents resolve themselves when the device recovers |
| 2.3 | Grouping by parent: when a switch and the devices behind it fail together, one incident opens, on the switch |
| 2.4 | Tickets created from incidents, one per incident. The old auto-ticket code is removed |
| 2.5 | On-call from the roster: the assistant on shift first, then engineers if nobody acknowledges in time |
| 2.6 | Alert channels: in-app, email, Telegram bot, phone push. Each alert has a unique key, so retries never alert anyone twice |

## Later

**Phase 3: live screens and maps.** WebSocket updates replace polling (a new ADR supersedes ADR-0005). A network map shows devices and their parents. Floor plans get live access point markers and a client heatmap.

**Phase 4: rounds on a phone.** An installable web app keeps checklist drafts offline. Each submission carries a key the server accepts once, and QR stickers on lab PCs and access points open the right form.

**Phase 5, in any order:**
- Normal-traffic baselines and anomaly alerts.
- Capacity forecasts.
- Risk scores fed by incident history.
- An AI helper that writes incident summaries, suggests ticket categories, and answers questions through read-only tools with the asking user's permissions.
- A public status page where students report faults by QR code. Their reports feed the incident engine.
- More than one campus.

## Not planned

- **Rewriting the screens in React.** Blade with Alpine already serves the users, and Phase 3 makes it live. A rewrite would take months and change nothing a user can see.
- **Microservices, Kafka or Kubernetes.** They solve problems of many teams and very large scale.
- **A machine-learning model trained now.** On simulated data it would only learn the simulator. Baselines on real readings come first.

## Risks

- **Home routers rarely support SNMP.** Ping and HTTP checks work on any network, so home testing still covers most of Phase 1.
- **Each outside-facing feature is a way in:** the ingest API, the Telegram bot, a public page. Each gets authentication, rate limits and a security review.
- **Scope.** Phases 0 to 2 are a lot of work. Doing them well comes before starting Phase 3.
