# Glossary

> **Last updated:** 2026-11-01

One concept, one word. Use these exactly, in code and in the UI.

---

## Domain terms

| Term | Means | Do not call it |
|---|---|---|
| **Network device** | Anything on the campus network that Atlas watches: an access point, switch, router, server or other. One row in `network_devices`, identified by `code`, with an optional parent device. | asset, host, node |
| **Access Point (AP)** | A physical Wi-Fi transmitter in a campus location. The network device kind `access_point`. | router, hotspot, node |
| **Agent** | Whatever measures network devices and sends Atlas their readings. Each device names the agent that watches it. | collector, probe, poller |
| **Probe agent** | An agent running on a machine inside a network, which posts batches to the ingest API with its own token. | sensor, client |
| **Simulator** | The virtual agent inside Atlas. It makes up plausible readings for the devices it watches, and has no token. | mock, fake driver |
| **Ingest API** | `POST /api/v1/readings`, where probe agents send batches of readings. | webhook, endpoint |
| **Sequence number** | The number an agent gives each reading, so a batch sent twice is stored once. | id, counter |
| **Silent** | An agent not heard from for `ingest.silent_after_minutes`. Its devices turn Unknown. | offline, down |
| **Telemetry** | Measurements taken from a network device at a point in time: latency, client count, packet loss, channel utilisation, throughput. | stats, metrics, logs |
| **Reading** | One telemetry record for one device at one moment, from one agent. | sample, datapoint |
| **Client count** | Number of devices currently associated with an AP. | users, connections |
| **Saturation** | Client count as a proportion of the AP's rated capacity. | load, usage |
| **Lab PC** | A university-owned workstation in a lab, identified by `asset_tag`. | computer, machine |
| **Location** | A physical place that houses assets — a lab, the library, the cafeteria. | room, site, zone |
| **Inspection** | One assistant completing one checklist against one lab PC at one time. | check, audit |
| **Round** | All the inspections an assistant performs during one shift. | patrol, sweep |
| **Checklist template** | The versioned list of items an inspection asks about. | form, questionnaire |
| **Checklist item** | A single question in a template, e.g. "Monitor displays correctly". | field, question |
| **Ticket** | A tracked report of a fault, from creation to closure. | issue, request, case, job |
| **Shift** | A dated, time-boxed period an assistant is rostered to work. | duty, slot |
| **Roster** | The overall schedule of shifts. | rota, timetable |
| **Handover** | Notes an assistant leaves when their shift ends. | changeover, handoff |
| **Alarm** | A scheduled reminder or alert delivered to a user in-app. | reminder |
| **Risk score** | A 0–100 number expressing how likely an asset is to fail. | health score, rating |
| **Risk band** | The category a risk score falls into: Low, Medium, High, Critical. | level, tier |
| **Factors** | The named contributions that produced a risk score, stored as JSON. | reasons, inputs |

> **Alarm vs notification.** In the schema and services it is an **alarm**. In the user interface it is presented as a **notification** (the bell icon). Both words are correct in their own layer; do not mix them within one layer.

## Status vocabulary

Use exactly these strings. They are enum values and UI labels at once.

| Entity | Statuses |
|---|---|
| **Lab PC** | `operational` · `degraded` · `faulty` · `retired` |
| **Network device** | `online` · `degraded` · `offline` · `unknown` · `maintenance` |
| **Ticket** | `open` · `acknowledged` · `in_progress` · `resolved` · `closed` · `reopened` |
| **Ticket priority** | `low` · `medium` · `critical` |
| **Inspection result** | `pass` · `pass_with_issues` · `fail` |
| **Checklist item result** | `ok` · `issue` · `na` |
| **Shift** | `scheduled` · `checked_in` · `completed` · `missed` |
| **Risk band** | `low` · `medium` · `high` · `critical` |

> **Degraded vs faulty.** *Degraded* means still working but below threshold. *Faulty* means not working. They are different states with different urgency; do not use them interchangeably.
>
> **Offline vs unknown.** *Offline* means the device stopped answering while its agent was listening, and raises a ticket. *Unknown* means its agent went silent, so nobody can say how the device is. It raises no ticket.

## Roles

| Role | Code value | Who |
|---|---|---|
| **Student IT Assistant** | `assistant` | Intern students doing lab rounds. Mobile-first users. |
| **Network Engineer** | `engineer` | Staff managing network health and resolving tickets. Desktop users. |
| **System Administrator** | `admin` | Manages accounts, inventory, rosters and configuration. |

## Project terms

| Term | Means |
|---|---|
| **Module** | One of the seven feature areas, M1 to M7. |
| **Phase** | A stage of the 2.0 roadmap, 0 to 5, each made of numbered tasks such as 1.6. See [roadmap.md](roadmap.md). |
| **ADR** | Architecture Decision Record. A numbered, immutable record of a significant technical decision. |
| **Feature freeze** | 9 Nov 2026. After this, only bug fixes and documentation. |
| **Demo data** | Seeded records tagged `is_demo = true`, removable with one command. |
| **Reference data** | The minimum records a real deployment needs: locations, checklist templates, shift templates. Never demo data. The first administrator comes from `atlas:create-admin`, not a seeder. |
