# M1 — Identity, Roles and Audit Log

**Track:** A · **Owner:** *unassigned* · **Sprint:** 1 · **Depends on:** nothing · **Blocks:** everything

> This module must be first and must be solid. Every other module has foreign keys into `users`.

## Purpose

Authenticate users, enforce role boundaries, let administrators manage accounts, and record an attributable trail of significant actions.

## Requirements covered

FR-101, FR-102, FR-103, FR-104, FR-105, FR-106, FR-401, FR-402, FR-409, FR-509
NFR-03, NFR-04, NFR-06

## Tables owned

`users` · `audit_logs` — see [../04-database.md](../04-database.md)

## Components

| Kind | Items |
|---|---|
| Models | `User`, `AuditLog` |
| Enums | `UserRole` |
| Controllers | `Auth\*` (Breeze, register removed), `Admin\UserController`, `Admin\AuditLogController` |
| Middleware | `EnsureUserHasRole` |
| Services | — (thin enough for the controller plus an observer) |
| Observer | `AuditObserver`, registered for every auditable model |
| Policies | `UserPolicy` |
| Commands | — |

## Screens

Login · Forgot password · Reset password · Force password change · Profile
Admin: user index, create, edit, show · Audit log with filters

## Key decisions

- **No self-registration.** Install Breeze, then delete the `register` route and view. Blocked on [Q6](../02-open-questions.md).
- **Role is a string column** cast to `UserRole`, not a roles table. Three fixed roles, no runtime management. See [ADR-0004](../decisions/ADR-0004-string-columns-not-mysql-enum.md).
- **Deactivate, never delete.** `is_active = false` blocks login; soft delete preserves history.
- **Audit via a model observer**, not scattered calls. One place, cannot be forgotten.
- **Middleware for area access, Policy for record access.** Never `if ($user->role === ...)` in a controller.

## Open questions

- [Q6](../02-open-questions.md) — who creates accounts, and is a forced password change on first login wanted? **Blocks Sprint 0.**

## Definition of done

- [x] All three roles can sign in and land on their own home page — `DashboardRedirect`, covered by `dashboard_redirect_uses_the_role_home_route_when_it_exists`
- [x] A user of one role gets 403 on another role's route — `EnsureUserHasRole`, and a deactivated user is refused at login and at every guarded route
- [x] Admin can create, edit, deactivate and reactivate a user
- [x] **Password reset works end to end, by real email.** Not through the `log` mailer, which is what this line originally said and which turned out to be the problem: the screen claimed to have emailed a link while writing it into `storage/logs/laravel.log`. It now goes through Gmail SMTP to a deliverable address, and was confirmed arriving on a phone on 2026-08-29. The link also works in a browser that is already signed in, and a reset ends every session that account has open. See [Q11](../02-open-questions.md#q11).
- [x] Every create, update and delete of a user writes an audit entry with actor, action and IP
- [x] Feature tests: login success, login failure, role denial, admin creates user
- [ ] DFD 1, data dictionary and flowchart drafted for the report — **not started, and the only thing left in M1**

