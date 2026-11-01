# M8 — Incidents and On-call

**Depends on:** M4, M5, M6 · **Blocks:** nothing

> **Last updated:** 2026-11-02

## Purpose

Turn what the network does into one record per fault, tell the right person, and keep telling more people until somebody takes it. Phase 2 of the roadmap, decided in [ADR-0014](../decisions/ADR-0014-incidents-and-domain-events.md).

## Requirements covered

FR-503, FR-511 to FR-517

## Tables owned

`incidents` · `incident_devices` · `incident_events` · `incident_alerts` · `device_status_changes`, and `users.telegram_chat_id`

## Components

| Kind | Items |
|---|---|
| Events | `DeviceStatusesChanged` (one per round or batch), `AgentSilenceChanged`, published by Telemetry after its transaction commits |
| Listener | `OpenAndResolveIncidents`, on the queue, 5 tries. The only listener for Telemetry's events |
| Models | `Incident`, `IncidentEvent`, `IncidentAlert`, `DeviceStatusChange` |
| Enums | `IncidentRule` (offline, degraded, flapping, silent, each with its escalation steps), `IncidentStatus`, `AlertChannel` |
| Services | `IncidentService` (rules, grouping, sweep, acknowledge) · `IncidentTickets` · `OnCall` (who, and when the next people) · `IncidentAlerts` (claim, then send) · `IncidentLog` (the timeline) |
| Controller | `IncidentController`, one screen under each role's prefix |
| Command | `atlas:watch-incidents`, every minute: the sweep, then escalation |

## How a fault travels

1. A reading or a sweep changes a device's status. Telemetry publishes `DeviceStatusesChanged` for the batch once its transaction commits. It imports nothing from Tickets, Shifts or Incidents, and `ArchitectureTest` fails the build if it does.
2. The listener, on the queue, takes a lock on `incidents` and goes through the changes, highest devices first:
   - **Offline.** If a device above it already has an open offline incident, the device joins it (`incident_devices`). Otherwise it opens its own, takes in any open offline incidents of devices below it (resolved as `grouped`, pointing at the new one), raises the ticket and starts on-call.
   - **Back** (Online, Degraded or Maintenance from Offline). Its incident resolves. Any device in it still offline then starts again on its own, since it is now a fault of its own.
   - **Degraded** from Online or Unknown opens a degraded incident, at most once per `thresholds.degraded_alert_cooldown_min`. Back to Online resolves it. Coming back from Offline through Degraded is recovery, not a new fault.
   - **Flapping.** Every change is kept in `device_status_changes`. `incidents.flapping_changes` of them inside `incidents.flapping_window_min` open one flapping incident, which the sweep resolves once the device has kept one status for a whole window.
3. `AgentSilenceChanged` opens a silent incident on the agent, and the agent's next batch resolves it.
4. The sweep runs every minute and does all of the above from current state instead of events: every offline device not in an open offline incident, every open incident whose device or agent is back. A lost event, or a listener whose five tries all failed, costs at most a minute.

One open incident per rule and device, and per rule and agent, is a partial unique index. The lock means two workers never decide about the same switch at once.

## Tickets

An offline incident raises one ticket as it opens: Critical, Network, reported in the system's name by the first administrator. If the device already has an open ticket, raised by hand or by an earlier outage, the incident uses that one, so a device that fails three times in a day has one ticket. An engineer or administrator can raise a ticket from any other incident on a device. Recovery does not close the ticket: whoever works it does.

## On-call

`IncidentRule::escalation()` lists the steps. For a device fault:

| Step | Who | When |
|---|---|---|
| 0 | Assistants checked in to a shift today. Those whose shift is in the device's location, alone, if there are any | When it opens |
| 1 | Every active engineer | `incidents.escalate_after_min` later, if nobody acknowledged it |
| 2 | Every active administrator | The same again |

A step with nobody in it, such as no assistant on shift at night, is skipped at once. A silent agent is configuration, so it goes to engineers and administrators together, in one step. Acknowledging, from the incident's page, stops the escalation; the incident still resolves itself.

## Alerts

`IncidentAlerts` claims each alert before sending it: a row in `incident_alerts`, unique on incident, person, step and channel. The claim that inserts the row queues the send; a second sweep, a retry or a second worker finds the row and sends nothing. The send checks `sent_at` first, so a retry after a send that worked sends nothing.

| Channel | When | Once? |
|---|---|---|
| In the app | Always. An `Alarm` about the incident, which the bell and the notifications list show | Exactly once: the alarm and `sent_at` are one transaction |
| Email | `MAIL_MAILER` is not `log` | Once, unless the worker dies between the mail server accepting it and `sent_at` being saved |
| Telegram | `ATLAS_TELEGRAM_BOT_TOKEN` is set and the person has a chat id on their profile | The same |

A send that fails is retried by the worker, and the last failure is saved on the claim as `failed_at` and `error`, which the incident's page shows. Phone push is not built.

## Screens

Every role: **Incidents** (active, with those nobody has taken first; resolved) and an incident's page: what it is, the devices in it and whether each is back, who was told on which channel, the timeline, and Acknowledge. Engineers and administrators also get **Raise ticket** on an incident that has none. An alert in the notifications list opens its incident.

## Key decisions

- **State is the source of truth, events are the fast path.** The sweep makes a lost event harmless, so the listener can be simple and the queue at-least-once.
- **One lock for every incident write.** Incidents change a few times an hour; grouping reads several rows before it decides, and a lock is simpler to prove than per-row locks across a device tree.
- **A ticket per incident, not per device-outage.** Repeated outages of one device reuse its open ticket, which is FR-503's rule carried over.
- **Recovery resolves the incident, not the ticket.** The ticket is the record of work, and the fault may come back.

## Done when

- [x] An offline device opens one incident and one ticket; recovery resolves the incident
- [x] A switch and the access points behind it are one incident, whichever fails first; a device still down when the switch recovers gets its own
- [x] Degraded, flapping and silent incidents open and resolve
- [x] The assistant on shift in the room is told first, then engineers, then administrators; acknowledging stops it
- [x] An alert is sent once per person, step and channel; a failed Telegram send is retried and recorded
- [x] The sweep opens and resolves what a lost event missed, and changes nothing when run twice
- [x] Every role's incident screens render empty and populated
