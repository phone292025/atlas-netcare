# ADR-0018: Check every screen with axe in the browser tests

**Status:** Accepted
**Date:** 2026-11-01
**Deciders:** Phone Myat Ko

## Context

NFR-05 and NFR-10 ask for a checklist usable on a phone and every control reachable by keyboard with visible focus. `accessibility.spec.js` checks those two by hand-written rules: no sideways scrolling at 375 px, and a Tab sweep that fails on a stop with no visible focus. Neither catches a missing label, a button with no name, a heading order that skips, an ARIA attribute on the wrong element, or text whose contrast is too low, in either theme. A review of the branch named that gap: the hand-written checks cover two rules out of the dozens WCAG 2.1 AA has.

## Decision

The browser tests run **axe-core** on every screen they visit, through `@axe-core/playwright` 4.14.0, a dev dependency pinned exactly in `package.json`. It checks the WCAG 2.0 and 2.1 A and AA rules, in the light theme and the dark one, and the test fails on any violation, listing the rule and the element.

It is a dev dependency only: nothing of it reaches the image, which builds the front end with `npm ci` and ships the built files.

## Consequences

**Easier**
- A missing label or a contrast drop fails the build on the screen that has it, instead of in a manual audit before a release.
- The rules come from Deque's maintained rule set, not from this project's guesses.

**Harder**
- One more package to update. Renovate-style bumps go through the browser tests like any other change.
- axe finds what can be found automatically, roughly half of WCAG. Keyboard use and focus order stay with the hand-written sweep, and a real screen reader is still a manual check.

## Alternatives considered

**pa11y or Lighthouse in CI.** Both use axe or similar rules underneath, but run against URLs from outside the test, so they would need their own sign-in for every role. Inside Playwright the tests' signed-in sessions are already there.

**Hand-written checks for each rule.** That is what `accessibility.spec.js` started with. Writing contrast arithmetic and ARIA validity by hand is a library's job.
