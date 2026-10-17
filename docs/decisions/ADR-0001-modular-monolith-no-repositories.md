# ADR-0001: Build a modular monolith on standard Laravel MVC, with no repository pattern

**Status:** Accepted
**Date:** 2026-08-26
**Deciders:** Group Atlas

## Context

Seven students must deliver a working, error-free system in eight weeks of development, and each of us must be able to explain our part. Several of us are learning Laravel as we go.

What the project needs is code that is efficient, validated, consistent, readable and commented, in a structure every one of us can explain. Architectural sophistication buys none of that, and a layer nobody can explain is a cost.

A repository layer over Eloquent is a common suggestion for "clean architecture". It would mean an interface and an implementation for each of 17 models.

## Decision

Standard Laravel MVC, organised as a modular monolith, with a thin service layer holding all business logic.

- Controllers do HTTP only: validate through a Form Request, call one service method, return a view or JSON.
- Services hold business logic and are what unit tests point at.
- Eloquent models are used directly. **No repository interfaces.**
- Modules are separated by folder, not by package boundaries.

## Consequences

**Easier**
- Any member can navigate the codebase within a day.
- Business logic is unit-testable without HTTP.
- The whole architecture fits on one slide and survives questioning.
- 34 fewer files than the repository alternative.

**Harder**
- Business logic sits in services by convention, not enforcement. A rushed member can still put logic in a controller; code review has to catch it.
- Swapping the ORM would be a large change. We accept this — we are never swapping the ORM.

## Alternatives considered

**Repository pattern over all models.** Rejected: Eloquent already *is* the data-access abstraction. Adding another one doubles the file count so that a thing we will never do becomes slightly easier. Abstract what varies (see ADR-0002), not what does not.

**Domain-driven design with bounded contexts.** Rejected: appropriate at a scale we are nowhere near, and the vocabulary alone would cost a week of team learning.

**No service layer, logic in controllers.** Rejected: makes business rules untestable and produces 200-line controller actions that are impossible to review.

## Notes

**2026-10-17.** The first Decision bullet is stricter than the code. Decisions do go to a service. Simpler writes stay in the controller: the admin CRUD screens (users, locations, lab PCs, access points, shift templates) and a few small actions write the model after validation and the role check, and `index` actions build their own filtered query. Both follow `AGENTS.md` hard rule 1: logic moves to a service when a second caller needs it. The decision itself stands. See [03-architecture.md](../03-architecture.md#two-kinds-of-controller).
