# M4 — Workspace Ticketing

**Track:** A · **Owner:** *unassigned* · **Sprint:** 3 · **Depends on:** M1, M2, M3 · **Blocks:** M7

## Purpose

One traceable queue from an assistant's fault report to an engineer's resolution, replacing ad-hoc email and verbal reporting.

## Requirements covered

FR-206, FR-207, FR-208, FR-306, FR-307, FR-308, FR-503, FR-507

## Tables owned

`tickets` · `ticket_comments`

## Components

| Kind | Items |
|---|---|
| Models | `Ticket`, `TicketComment` |
| Enums | `TicketStatus`, `TicketPriority`, `TicketCategory` |
| Controllers | `Assistant\TicketController`, `Engineer\TicketController`, `TicketCommentController` |
| Services | `TicketService` — open, assign, transition, compute SLA due time |
| Policies | `TicketPolicy` — who may acknowledge, resolve, close, reopen |
| Components | `<x-priority-pill>`, `<x-timeline>` |

## Screens

Assistant: my tickets · raise ticket · ticket detail
Engineer: ticket queue with filters (status, priority, location, assignee) · ticket detail with timeline, comments and transition actions

## Key decisions

- **Polymorphic `ticketable`.** One ticket table serves both lab PCs and access points via `ticketable_type` / `ticketable_id`. The alternative is two ticket tables or two nullable foreign keys, and both are worse.
- **State transitions live in `TicketService`, never in a controller.** The service is the only thing that may change `status`, and it validates the transition is legal.
- **`ticket_number` is human-readable and generated** — `TKT-2026-00417`. Reserve it in the service, not the database default.
- **`resolution_notes` is required to resolve.** No silent closures.
- **SLA `due_at` is computed at creation** from priority and `config('atlas.sla_hours')`. M6 raises the breach alarm.
- **Auto-tickets from AP offline events** (FR-503) are raised by M5's collector calling `TicketService::open()`. Agree that interface with M5's owner.

## Open questions

- 🔴 [Q3](../04-open-questions.md) — the full lifecycle and SLA are **not yet decided**. This blocks the schema. Settle before Sprint 0.

## Definition of done

- [ ] Assistant can raise a ticket manually and from a failed checklist item
- [ ] Engineer can filter the queue and move a ticket through every legal transition
- [ ] An illegal transition is rejected by the service, not just hidden in the UI
- [ ] Comments work, including internal-only visibility
- [ ] `due_at` computes correctly per priority; breaching raises an alarm
- [ ] An access point going offline auto-raises exactly one ticket, not one per reading
- [ ] Feature tests: raise, transition, illegal transition rejected, authorisation
- [ ] DFD 1, data dictionary and flowchart drafted
