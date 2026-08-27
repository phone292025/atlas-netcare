# ADR-0008: Pin the project to Laravel 12, not the latest release

**Status:** Accepted
**Date:** 2026-08-27
**Deciders:** Group Atlas

## Context

At the time of installation the current Laravel release is **13.10.1**. Checking its requirements before installing revealed:

| Package | Requires |
|---|---|
| `laravel/laravel` v13 | PHP `^8.3` |
| `laravel/laravel` v12 | PHP `^8.2` |
| `laravel/laravel` v11 | PHP `^8.2` |
| `laravel/breeze` v2.4.2 | PHP `^8.2`, Illuminate `^11.0 \| ^12.0 \| ^13.0` |

The development machine runs **XAMPP 8.2.12**, which ships PHP 8.2.12. Laravel 13 will not install on it.

Seven students will each run this project on their own machine. Most will install the same XAMPP build, because that is what the Installation Guide will tell them to do and what the university labs are likely to have.

## Decision

Pin the project to **Laravel 12**, with `laravel/framework: ^12.0` in `composer.json`. Use **Breeze 2.4.2**, which supports Laravel 12 and provides the Blade + Alpine scaffolding this project actually wants.

Do not upgrade PHP or XAMPP.

## Consequences

**Easier**
- Installs on PHP 8.2, which is what the development machine and most teammates already have.
- No XAMPP upgrade, so nobody risks breaking a working MySQL install three weeks before a deadline.
- Breeze gives us Blade and Alpine directly, matching [ADR-0006](ADR-0006-alpine-chartjs-not-livewire.md). Laravel's newer starter kits ship React, Vue or Livewire, none of which we want.
- `composer.lock` is committed, so all seven members get byte-identical dependencies.

**Harder**
- We are one major version behind current. For a fourteen-week project with a fixed submission date this costs nothing; no feature we need is Laravel 13-only.
- If someone installs a newer XAMPP with PHP 8.3 or 8.4, `composer install` still works — Laravel 12 allows `^8.2`, which includes 8.3 and 8.4. The pin is on Laravel, not on PHP, so a newer PHP is not a problem.

**Rule that follows from this**
Nobody runs `composer update`. It would drift dependencies away from the committed lock file and could pull a Laravel version that no longer matches this ADR. Use `composer install`. If a package genuinely must be added, the module owner does it, commits the updated lock file, and tells the team.

## Alternatives considered

**Upgrade XAMPP to PHP 8.3+ and use Laravel 13.** Rejected: it makes every teammate's setup a prerequisite risk, for no feature we need. An upgrade that breaks one member's MySQL in week 9 costs more than being one version behind ever will.

**Laravel 11.** Rejected: also PHP 8.2 compatible, but a year older with a shorter support window and no compensating benefit over 12.

**Leave the version unpinned and take whatever installs.** Rejected: seven machines would drift to different versions, and "works on my machine" is the single most expensive bug class on a team this size.
