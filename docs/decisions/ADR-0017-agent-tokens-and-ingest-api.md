# ADR-0017: Agents authenticate with one token each, and push readings to one ingest endpoint

**Status:** Accepted
**Date:** 2026-10-28
**Deciders:** Phone Myat Ko

## Context

[ADR-0010](ADR-0010-probe-agents-push-readings.md) decided that probe agents inside a network measure devices and push readings to Atlas. Roadmap tasks 1.2 and 1.3 build the Atlas side: agents an administrator registers, and an API they send readings to.

It is the first part of Atlas that something other than a signed-in person can write to. Everything else goes through the web routes, behind a session, CSRF and a role. The readings it accepts change device status, and status changes raise tickets and alert engineers (`TelemetryCollector::tick()`). A forged or flooded feed would page people about faults that don't exist.

An agent runs unattended on a machine Atlas doesn't control, buffers readings while Atlas is unreachable, and retries. So the same batch can arrive twice, and readings can arrive hours late.

## Decision

### Agents

- An administrator registers an agent by name. Atlas generates its token, `atl_` and 40 random letters and digits, and shows it once. Only its SHA-256 hash is stored, with the last four characters as a hint to recognise it. The token is high-entropy and random, so a slow password hash would add nothing.
- The token is shown in the response to the request that made it, never put in the session, so it's never written to the sessions table.
- **Rotating** replaces the token at once. **Retiring** an agent (a soft delete) stops its token working, and keeps its readings.
- Each network device has at most one agent watching it (`network_devices.agent_id`). An agent may report only devices assigned to it.

### The ingest endpoint

`POST /api/v1/readings`, on the stateless `api` middleware group: no session, no cookies, no CSRF.

```json
{
  "sent_at": "2026-10-28T03:00:05Z",
  "agent_version": "0.1.0",
  "readings": [
    {"sequence": 1201, "device": "SW-L6-IT-01", "measured_at": "2026-10-28T02:59:30Z",
     "reachable": true, "latency_ms": 2, "packet_loss_pct": 0}
  ]
}
```

- `Authorization: Bearer <token>`. A missing, wrong or retired token gets 401.
- At most 500 readings and 256 KB per batch. An empty `readings` array is a heartbeat.
- Each reading has a `sequence`, unique per agent and counting up. Atlas stores it under a unique key on agent, sequence and measured time, so a batch sent twice is stored once.
- A malformed batch is refused whole, with 422. Otherwise each reading gets its own outcome: **stored**, **duplicate** (already stored), or **rejected** with a reason. The agent drops every sequence the response lists, so a reading that can never be stored isn't retried forever.
- A reading is rejected if its device isn't one the agent watches, if it was measured more than 5 minutes in the future, or if it is older than `late_reading_days`. Unknown devices and other agents' devices get the same reason, so a token can't be used to list device codes.
- Each reading keeps the agent's `measured_at` (as `recorded_at`) and Atlas's `received_at`. Atlas records the agent's clock offset, `sent_at` minus the time it received the batch, and flags the agent when it is more than `clock_drift_seconds` out.
- Only the newest reading per device decides its status, so a late reading fills in history without moving the status backwards.
- The limits, windows and rates live in `config/atlas.php`, under `ingest`.

## Threats

Assumption for Phase 1: Atlas runs on one machine or a trusted local network. Anything else needs HTTPS in front of it first.

| Threat | Path | Controls |
|---|---|---|
| Forged readings raise false faults | Anyone who can reach the port posts readings | A valid token is required. A token can report only its own agent's devices |
| A stolen token | Read from the agent's config file, or sniffed off plain HTTP on the network | The damage is limited to that agent's devices. Rotate or retire it from the admin screen. Tokens are hashed, so a database dump yields none. **HTTPS is required beyond a trusted network** |
| Flooding | Many requests, or huge batches | A per-address limit before authentication and a per-agent limit after. A size and count cap per batch, and ranges on every value |
| Replay | A captured batch sent again | The unique key stores it once. Anything older than `late_reading_days` is refused |
| Time games | A reading dated far ahead stays "newest" and pins a status | More than 5 minutes ahead is refused, and clock drift is flagged |
| Learning device codes | Trying codes and reading the refusal | One reason for unknown devices and other agents' devices |
| Reaching the web app | Using the token anywhere else | The token works only on the ingest route. It carries no session and no role |

What an agent can't do: read any data back, add devices, raise a ticket directly, or report a device it doesn't watch. Tickets come only from status changes, behind the existing guards: one open ticket per access point, and a cooldown on degraded alerts.

Remaining risk: an agent whose token is stolen can make its own devices flap, and each fall to offline alerts the engineers. Phase 2's flapping rule and incidents are the fix.

## Review, 2026-10-28

Checked as built, before it shipped. Each control has a test in `tests/Feature/Api/ReadingIngestTest.php` or `tests/Feature/Admin/AgentManagementTest.php`:

- A request without a valid token, with the token in the query string, or from a retired agent is refused with 401, and nothing is stored. The token opens no web screen, and the API sets no session cookie.
- The token never reaches the session, the audit log or the agent's page after it is shown, and the stored hash doesn't work as a token.
- A malformed batch, an extra field, a repeated sequence or an out-of-range number is refused whole with 422. Over the reading cap gets 422, and over the size cap 413. Caddy also refuses any API body over 512 KB, sent with or without a length, before PHP reads it.
- A reading for another agent's device and for an unknown one get the same reason.
- A resent batch is stored once. A late reading doesn't move a status. Readings in one batch are judged in the order they were measured.
- An agent over its rate gets 429.
- Every query that takes agent input binds it. The one raw insert builds its column list from a constant.

Open: with `APP_DEBUG=true`, a server error answers an agent with a stack trace in JSON. The installation guide already says to turn it off before anyone else can reach the app.

## Consequences

**Easier**
- Any program that can send HTTPS and JSON can feed Atlas, so the Go agent is one client among possible others.
- A retry is always safe, and the agent's buffer can be emptied in any order.

**Harder**
- Tokens are secrets on machines Atlas doesn't control, and someone has to rotate one when a machine is lost.
- The endpoint needs HTTPS before Atlas is reachable from outside a trusted network.
- Late and duplicate readings are normal, so code that reads telemetry can't assume arrival order is measurement order.

## Alternatives considered

**Laravel Sanctum tokens.** They do the same job for users, but they're tied to a tokenable model with abilities and a session-based first party, none of which an agent has. A package for one hashed column wasn't worth it.

**Mutual TLS.** Stronger, since no secret crosses the wire, but certificates need a small CA and renewal on every agent, which is too heavy for a home network test. It stays an option for a campus.

**Signing each batch with HMAC.** It proves the body wasn't altered, but over HTTPS that adds little, and it still needs a shared secret on the agent.
