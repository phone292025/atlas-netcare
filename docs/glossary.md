# Glossary

> **Last updated:** 2026-08-26

Seven people writing one 50-page document need one word per concept. Use these exactly — in code, in the UI, and in the report.

---

## Domain terms

| Term | Means | Do not call it |
|---|---|---|
| **Access Point (AP)** | A physical Wi-Fi transmitter in a campus location. Identified by `ap_code`. | router, hotspot, node |
| **Telemetry** | Measurements taken from an access point at a point in time — latency, client count, packet loss, channel utilisation, throughput. | stats, metrics, logs |
| **Reading** | One telemetry record for one AP at one timestamp. | sample, datapoint |
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

Use exactly these strings. They are enum values, UI labels, and report terms all at once.

| Entity | Statuses |
|---|---|
| **Lab PC** | `operational` · `degraded` · `faulty` · `retired` |
| **Access Point** | `online` · `degraded` · `offline` · `maintenance` |
| **Ticket** | `open` · `acknowledged` · `in_progress` · `resolved` · `closed` · `reopened` |
| **Ticket priority** | `low` · `medium` · `critical` |
| **Inspection result** | `pass` · `pass_with_issues` · `fail` |
| **Checklist item result** | `ok` · `issue` · `na` |
| **Shift** | `scheduled` · `checked_in` · `completed` · `missed` |
| **Risk band** | `low` · `medium` · `high` · `critical` |

> **Degraded vs faulty.** *Degraded* means still working but below threshold. *Faulty* means not working. They are different states with different urgency; do not use them interchangeably.

## Roles

| Role | Code value | Who |
|---|---|---|
| **Student IT Assistant** | `assistant` | Intern students doing lab rounds. Mobile-first users. |
| **Network Engineer** | `engineer` | Staff managing network health and resolving tickets. Desktop users. |
| **System Administrator** | `admin` | Manages accounts, inventory, rosters and configuration. |

## Project terms

| Term | Means |
|---|---|
| **Module** | One of the seven feature areas, M1–M7, each owned by one member. |
| **Track** | Track A (Operations) or Track B (Data) — the two parallel work streams. |
| **ADR** | Architecture Decision Record. A numbered, immutable record of a significant technical decision. |
| **Design freeze** | The point after which the ERD and architecture do not change without an ADR. **Date not yet known** — see coursework/09-roadmap.md. |
| **Feature freeze** | 9 Nov 2026. After this, only bug fixes and documentation. |
| **Demo data** | Seeded records tagged `is_demo = true`, removable with one command. |
| **Reference data** | The minimum records a real deployment needs — admin account, locations, checklist templates. Never demo data. |
