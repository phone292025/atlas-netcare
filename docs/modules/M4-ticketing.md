# M4 — Workspace Ticketing

**Depends on:** M1, M2, M3 · **Blocks:** M7

> **Last updated:** 2026-10-22

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
| Components | `<x-priority-pill>`, `<x-timeline>`, `<x-ticket-due>`, `<x-sla-line>` |

## Screens

Assistant: my tickets · raise ticket · ticket detail
Engineer: ticket queue with filters (status, priority, location, assignee) · ticket detail with timeline, comments and transition actions

In the queue every ticket shows its time against its target as a line: the target, then what is left of it or how far past it the ticket went. A ticket nobody has carries a "Take it" button on its row. Under the whole open queue, "Finished this week" lists the last five tickets resolved in the past seven days.

## Key decisions

- **Polymorphic `ticketable`.** One ticket table serves both lab PCs and access points via `ticketable_type` / `ticketable_id`. The alternative is two ticket tables or two nullable foreign keys, and both are worse.
- **State transitions live in `TicketService`, never in a controller.** The service is the only thing that may change `status`, and it validates the transition is legal.
- **`ticket_number` is human-readable and generated** — `TKT-2026-00417`. Reserve it in the service, not the database default.
- **`resolution_notes` is required to resolve.** No silent closures.
- **SLA `due_at` is computed at creation** from priority and `config('atlas.sla_hours')`. M6 raises the breach alarm.
- **Auto-tickets from AP offline events** (FR-503) are raised by M5's collector calling `TicketService::open()`.
- **A Critical ticket alerts every engineer when a person raises it** (`TicketService::announce()`, threshold `atlas.new_ticket_alert_from`). Lower priorities wait in the queue, where an engineer finds them on their next look; a four-hour target cannot wait for that. The collector's tickets alert through `ap_offline` instead, and an engineer is not told about a ticket they raised. Added 2026-09-23, after a critical ticket raised in live testing reached nobody's bell.
- **A failed checklist answer is ticketed once** (FR-207). `tickets.inspection_result_id` records which answer; `open()` refuses a second, and the inspection summary shows the ticket instead of the Raise button. The pre-filled title reads "Failed check: …", because checklist items are worded as what should be true.
- **An engineer given a ticket by somebody else is told** (`ticket_assigned`). Taking one yourself, or returning it to the queue, tells nobody.
- **The timeline shows status changes and assignments as "updated the ticket"**, not "added an internal note": they are entries `TicketService` writes, recognised by `TicketComment::isRecordedAction()`. They stay internal, so a reporter follows progress by the status and the resolution notes.
- **A ticket notification opens the engineer's own ticket screen** for engineers and administrators, with the assign and progress buttons; it used to open the assistant's read-only one.

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

## How it was built

**The state machine is one array.** `TicketService::TRANSITIONS` maps each state to the states it may become, and `transition()` throws a `DomainException` for anything else. The map is deliberately one readable array so that ratifying a different lifecycle is a small edit rather than an archaeology exercise.

**Hiding a button is not a rule.** The UI renders its actions from `nextStates()`, so the screen never offers an illegal move — but the refusal lives in the service, and a hand-written POST is rejected regardless. There is a test that posts one.

**Three separate questions, answered in three places.** `TransitionTicketRequest` validates the shape of the request, `TicketPolicy` decides whether this person may make the move, and `TicketService` decides whether the move is legal at all. Collapsing any two of them is how "an engineer closed a ticket assigned to somebody else" becomes possible.

**Internal comments are filtered in the query, never in Blade.** `TicketComment::scopeVisibleTo()` is the only place visibility is decided. A view that decides it is one misplaced `@if` from showing an assistant what the engineers said about their report — and the mistake would be invisible in review, because the data is in the variable either way. An assistant who posts `is_internal=1` by hand does not get it either.

**Reopening clears the resolution.** Leaving `resolved_at` set would make the ticket count as resolved in every report while sitting in the queue as open work — the figures and the screen disagreeing about the same row.

**Ticket numbers are reserved in the service**, per calendar year, inside the same transaction that creates the ticket. The column is unique, so two simultaneous requests cannot both take a number — the loser hits the index rather than silently duplicating.

**The route layout follows the policy, not the roles.** Raising and reading your own tickets, transitioning, and commenting all sit outside the role-guarded groups, because closing and reopening belong to the reporter — an assistant behind `role:engineer` could never reach the route that lets them say "this was not actually fixed". Only the queue itself is engineer furniture.
