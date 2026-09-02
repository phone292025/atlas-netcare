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

- ✅ [Q3](../02-open-questions.md) — **settled 2026-08-28.** The proposed lifecycle and SLA were adopted as written and built. The team never met on it, so this was adopted rather than ratified: **if a different lifecycle is wanted, the state machine is one array in `TicketService::TRANSITIONS` and the SLA is three lines in `config/atlas.php`.**

## Definition of done

- [x] Assistant can raise a ticket manually and from a failed checklist item
- [x] Engineer can filter the queue and move a ticket through every legal transition
- [x] An illegal transition is rejected by the service, not just hidden in the UI
- [x] Comments work, including internal-only visibility
- [x] `due_at` computes correctly per priority — **breach alarm is M6's**, and `Ticket::scopeBreaching()` is the query it sweeps
- [~] An access point going offline auto-raises exactly one ticket — **the guard is built and tested (`TicketService::hasOutstandingFor()`), the caller is M5's collector**
- [x] Feature tests: raise, transition, illegal transition rejected, authorisation
- [ ] DFD 1, data dictionary and flowchart drafted — **outstanding, and it is a marked deliverable.**

## How it was built

**The state machine is one array.** `TicketService::TRANSITIONS` maps each state to the states it may become, and `transition()` throws a `DomainException` for anything else. The map is deliberately one readable array so that ratifying a different lifecycle is a small edit rather than an archaeology exercise.

**Hiding a button is not a rule.** The UI renders its actions from `nextStates()`, so the screen never offers an illegal move — but the refusal lives in the service, and a hand-written POST is rejected regardless. There is a test that posts one.

**Three separate questions, answered in three places.** `TransitionTicketRequest` validates the shape of the request, `TicketPolicy` decides whether this person may make the move, and `TicketService` decides whether the move is legal at all. Collapsing any two of them is how "an engineer closed a ticket assigned to somebody else" becomes possible.

**Internal comments are filtered in the query, never in Blade.** `TicketComment::scopeVisibleTo()` is the only place visibility is decided. A view that decides it is one misplaced `@if` from showing an assistant what the engineers said about their report — and the mistake would be invisible in review, because the data is in the variable either way. An assistant who posts `is_internal=1` by hand does not get it either.

**Reopening clears the resolution.** Leaving `resolved_at` set would make the ticket count as resolved in every report while sitting in the queue as open work — the figures and the screen disagreeing about the same row.

**Ticket numbers are reserved in the service**, per calendar year, inside the same transaction that creates the ticket. The column is unique, so two simultaneous requests cannot both take a number — the loser hits the index rather than silently duplicating.

**The route layout follows the policy, not the roles.** Raising and reading your own tickets, transitioning, and commenting all sit outside the role-guarded groups, because closing and reopening belong to the reporter — an assistant behind `role:engineer` could never reach the route that lets them say "this was not actually fixed". Only the queue itself is engineer furniture.
