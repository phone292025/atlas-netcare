# ADR-0015: Test the core flows in a browser with Playwright

**Status:** Accepted
**Date:** 2026-10-27
**Deciders:** Phone Myat Ko

## Context

Roadmap task 0.7 asks for browser tests of five core flows, run against the Docker stack: signing in as each role, raising and resolving a ticket, submitting an inspection, opening the Network Monitor, and printing the report.

The PHPUnit suite can't cover these. Its feature tests read the HTML the server sends, and they never run JavaScript or apply CSS. Two defects have already shipped past a green suite for that reason (`AGENTS.md`, "Verifying your work"). Much of what these flows depend on runs in the browser: the room plan on the ticket form, the checklist sections that fold up when answered, the monitor's charts and polling, and the report's switch to a light palette for paper.

Three constraints shape the choice:

- **The image carries no tests and no dev packages** (`Dockerfile`, `.dockerignore`), on purpose: its config is cached, and a test run against cached config wiped a database once. Tests of the stack therefore have to drive it from outside, over HTTP, the way a user does.
- **The ticket flow has two users in it.** An assistant raises the ticket and an engineer resolves it, so one test needs two signed-in sessions at once.
- **The suite runs on PHP 8.2**, on XAMPP and in CI.

## Decision

The browser tests use **Playwright**: `@playwright/test`, pinned to an exact version in `package.json`, with Chromium.

- The tests live in `tests/Browser/`, written in JavaScript like the rest of the front end.
- `npm run test:browser` builds the image from the working tree and starts a **separate Compose stack** for the run (project `atlas-browser`, image tag `browser-tests`, ports 8100 and 5433). Its `.env` is a fresh copy of `.env.example`, so mail goes to the log. The run seeds the demo data, creates the first administrator with `atlas:create-admin`, runs the tests, and removes the stack and its volume at the end.
- The tests never run against a stack that holds real data. They raise tickets and submit inspections, and a developer's own `.env` may send real mail.
- Each role signs in once, through the sign-in form, and the other tests reuse those sessions. So the tests run one at a time (`workers: 1`): two at once as the same role share one session, and one test's request can use up the other's flash message (2026-10-29).
- A test fails if the page logs a JavaScript error or a failed request.
- CI runs the browser tests as their own job, beside the PHPUnit runs.

## Consequences

**Easier**
- A broken script, a missing asset or a layout that hides a button fails CI, where before it waited for somebody to open the page.
- The tests drive what ships: the built image, its migrations and caches, and FrankenPHP, not `php artisan serve`.
- A failed test leaves a trace with the page, its network requests and its console, which `npx playwright show-trace` opens.

**Harder**
- A second test suite, in a second language, with its own conventions.
- Each machine downloads a Chromium build of about 120 MB once, with `npx playwright install chromium`.
- A run takes a few minutes, most of it building the image and starting the stack.
- The tests depend on `DemoDataSeeder`: its rooms, machines and checklist. Changing the seeder can break them.

## Alternatives considered

**Laravel Dusk.** It's PHP, like the rest of the suite. But it expects to run inside the app: its sign-in shortcut and its database traits need Dusk installed and the app's database within reach. The image has neither, so Dusk would test `php artisan serve` on the host, not the stack. It also drives ChromeDriver, whose version has to match the installed Chrome.

**Pest's browser plugin.** It runs Playwright underneath and keeps tests in PHP. It needs Pest 4, which needs PHP 8.3, and the suite runs on PHP 8.2 and PHPUnit.

**Cypress.** Its tests run inside one browser tab, and it can't drive two signed-in users in one test, which the ticket flow needs.
