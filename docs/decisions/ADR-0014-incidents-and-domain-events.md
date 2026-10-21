# ADR-0014: Put incidents between readings and tickets, and connect modules with events

**Status:** Accepted
**Date:** 2026-10-22
**Deciders:** Phone Myat Ko

## Context

In Atlas 1.0, a reading decides everything at once. When an access point goes offline, `TelemetryCollector::tick()` raises a ticket through `TicketService` and an alarm through `AlarmService`. Only one other module calls another directly: `TicketService` raises SLA alarms through `AlarmService`. Three things follow from the collector's calls:

- **A fault and a ticket are the same thing.** If twelve access points behind one switch fail together, that is twelve tickets for one broken switch.
- **"One open ticket per access point" is a check in PHP.** `hasOutstandingFor()` reads, then the collector writes. Nothing stops the scheduler's round and the "Generate sample" button both passing the check at the same moment.
- **Everything happens inside the scheduler's one-minute tick.** A slow reaction delays every reading after it, and there is no retry if sending an alert fails.

ADR-0010 brings real devices, which flap, fail in groups and recover on their own. The roster already knows who is on shift, but nothing uses that to decide who to tell.

## Decision

- **An incident sits between readings and tickets.** Detection rules turn readings into incidents: a value over its limit for N readings in a row, a device flapping on and off, or a device or agent gone silent. An incident opens, can be acknowledged, and resolves itself when the device recovers. A ticket is created from an incident, one per incident, when the fault needs work.
- **Devices have a parent.** For example, the switch an access point plugs into. When a parent and its children fail together, the system opens one incident for the parent.
- **The database enforces "only one".** A partial unique index allows only one open incident per rule and device. Each incident has at most one ticket. Each alert has a unique key: incident, recipient and escalation step. This needs ADR-0011's PostgreSQL.
- **Modules talk through domain events.** Telemetry publishes that a device's status changed and knows nothing about who listens. Incidents, tickets and alerts react to events. Listeners run on the queue, and are only sent after the database transaction commits.
- **The roster is the on-call schedule.** The assistant on shift is alerted first, then the engineers if nobody acknowledges within a configured time.
- **Alerts go out through channels behind one interface:** in-app, email, a Telegram bot and phone push. Sending happens on the queue, with retries, and the unique key makes a retry safe.
- **A test enforces the boundaries.** An architecture test fails the build if Telemetry imports Tickets or Shifts.

Phase 2 settles the rule format, the escalation timings and the first channels.

## Consequences

**Easier**
- One real fault gives one incident, one ticket and one alert.
- A failed alert is retried without anyone being alerted twice.
- Telemetry can change without touching tickets or alarms, and the reverse.
- New signals, such as a student reporting a fault by QR code, can open or join incidents without changing Telemetry.

**Harder**
- A queue worker must be running. ADR-0012's stack runs one.
- Events make the flow less obvious: you can't read the whole reaction to a fault in one method. The listeners for each event are listed in one place, and the docs keep that list current.
- Tickets raised automatically from incidents replace the current auto-ticket code, and its tests are rewritten.

## Alternatives considered

**Keep the direct calls and add a database lock.** That fixes the race, but not the twelve-tickets-for-one-switch problem or the lack of retries.

**A message broker such as RabbitMQ or Kafka.** Built for many services and high volume. Atlas is one app with one database, so Laravel's database queue covers it with nothing extra to run.

**Group alerts in the notification layer only.** Grouping alerts would hide duplicates from people, but they would still exist as twelve tickets in the data.
