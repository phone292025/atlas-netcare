# Build Log

> **Last updated:** 2026-08-28
>
> **Read this first in any new development session.** It is the handover between sessions: what exists, what is half-done, and what is next. Conversation history does not survive; this file does.

---

## Where the build is right now

| | |
|---|---|
| **Stage** | Sprint 0 — scaffolding |
| **Last session** | 2026-08-28 |
| **Laravel installed** | ✅ 12.68.0, Breeze Blade stack |
| **Database created** | ✅ `atlas_netcare` on MariaDB 10.4.32, base Laravel migrations only |
| **Next action** | Stage 4: foundation migrations, ReferenceSeeder, empty DemoDataSeeder |

## Sprint 0 stages

Sprint 0 is broken into five stages so each can be reviewed before the next starts. A stage is not done until its checkbox is ticked here.

| # | Stage | Status |
|---|---|---|
| 0 | Repository, gitignore, branch, docs committed | ✅ Done 2026-08-27 |
| 1 | Laravel install, `.env`, Breeze with register removed, Vite + Tailwind + Alpine + Chart.js | ✅ Done 2026-08-27 |
| 2 | `config/atlas.php`, all eleven enums, `EnsureUserHasRole` middleware | ✅ Done 2026-08-27 |
| 3 | Layouts, role-filtered sidebar, shared Blade components | ✅ Done 2026-08-28 — 3a shell/tones/icons, 3b page furniture, 3c dashboards/forms |
| 4 | Foundation migrations (`users`, `locations`, `lab_pcs`, `access_points`), `ReferenceSeeder`, empty `DemoDataSeeder` | ⬜ Not started |

Sprint 0 is complete when all five are ticked **and all seven members have run the app successfully**.

---

## Environment on the development machine

Checked 2026-08-27.

| Tool | Status | Notes |
|---|---|---|
| PHP | ✅ 8.2.12 | XAMPP, at `C:\xampp\php\php.exe`. Laravel 11 needs 8.2+, so this is fine. |
| MySQL | ✅ Present | XAMPP, at `C:\xampp\mysql\bin\mysql.exe`. Start it from the XAMPP Control Panel. |
| Node | ✅ 24.15.0 | For Vite |
| npm | ✅ 11.12.1 | |
| Git | ✅ 2.54.0 | |
| **Composer** | ❌ **Missing** | **Required before anything else.** |
| GitHub CLI | ❌ Missing | Only needed if creating the remote from the terminal |

> Other team members will need the same set. Once Stage 1 is done, the verified setup steps go in the README and eventually into the Installation Guide for the report appendix.

---

## Session log

Oldest first — append new entries at the end. One entry per development session: what was done, what was decided, what is next.

### 2026-08-27 — Stage 0

**Done**
- Documentation committed (`4e86655`) — 35 files, `docs/` is now under version control
- Sprint 0 stance on Q3 and Q6 recorded in the decision log (`7e0b1a7`) without flipping either question to `Decided`, since the team has not ratified them
- `.gitignore` (Laravel-aware) and `.gitattributes` added (`c91325f`)
- Branch renamed `master` → `main` to match the docs
- This build log created and wired into `docs/INDEX.md`

**Found**
- Composer is **not installed** on the development machine. Stage 1 cannot start without it.
- GitHub CLI is not installed, so a remote repository must be created through the website or after installing `gh`.
- XAMPP provides PHP 8.2.12 and MySQL, which is sufficient for Laravel 11.

**Decided**
- Sprint 0 split into five reviewable stages, recorded above.
- Provisional-values rule for blocked questions: values already documented in `06-database.md` and `05-architecture.md` may be written into enums and config, marked provisional in a code comment, but **no behaviour that depends on an undecided question is built**. So `TicketStatus` cases and `atlas.sla_hours` can exist; the state machine, transition guards and SLA breach logic cannot. Same for Q6: `register` removed and `must_change_password` column created, but no first-login enforcement middleware.

**Next**
- Install Composer, then Stage 1.

**Still open**
- Q3 (ticket lifecycle) and Q6 (account creation) remain `Open`. Neither blocks Sprint 0 under the provisional-values rule above, but both must be settled before M1 and M4 start.

### 2026-08-27 — Stage 1 (in progress)

**Environment resolved**
- Composer 2.10.2 installed to `C:\xampp\php\composer.phar`, with a `composer.bat` wrapper and a POSIX shim so the `composer` command works in both cmd and Git Bash. Installer signature verified against `composer.github.io/installer.sig` before running.
- PHP extension `zip` is **not enabled** in this XAMPP build. Composer falls back to a slower extraction path but works. Worth enabling in `php.ini` if installs feel slow.
- MySQL is **not currently running**. Start it from the XAMPP Control Panel before any `migrate` command.

**Version decision**
- Laravel **13.10.1** is current but requires PHP `^8.3`; this machine has PHP 8.2.12. Pinned to **Laravel 12** (installed 12.68.0), which requires `^8.2`. Breeze 2.4.2 supports it. Recorded as [ADR-0008](../decisions/ADR-0008-laravel-12-pinned.md).
- **Nobody runs `composer update`.** Use `composer install` so everyone matches the committed lock file.

**Done**
- Laravel 12.68.0 installed at the project root alongside `docs/`
- Project `README.md`, `.gitignore` and `.gitattributes` preserved over Laravel's versions; `*.log` added to ours
- Default SQLite database removed; `.env` and `.env.example` switched to MySQL, database name `atlas_netcare`
- `ATLAS_TELEMETRY_DRIVER`, `ATLAS_DEMO_MODE` and `ATLAS_POLL_INTERVAL` added to `.env.example` with explanatory comments
- Breeze 2.4.2 installed with the **Blade** stack, bringing Tailwind 3.4.19, Alpine 3 and Vite 7
- **Registration removed** per Q6 working assumption: `register` routes, `RegisteredUserController`, `register.blade.php`, `RegistrationTest` and the welcome-page link are all gone. A comment at the top of `routes/auth.php` explains why, so nobody re-adds it.
- **Self-delete-account removed**: Breeze lets a user delete their own account, which contradicts M1's "deactivate, never delete" and NFR-06 auditability. Route, controller method, form partial and test removed; `ProfileTest` rewritten to assert DELETE `/profile` returns 405.
- Chart.js installed and wired into `resources/js/app.js` with shared defaults, so every graph in the system looks consistent
- Unused `@tailwindcss/vite` v4 dependency removed — Breeze installed a Tailwind **3** setup with `tailwind.config.js`, which is what `08-ui-conventions.md` already specifies
- `README.md` now carries verified setup steps for teammates; these become the Installation Guide in the report appendix

**Verified**
- `php artisan test` — 22 passed, 49 assertions
- `php artisan view:cache` — every Blade template compiles
- `php artisan route:list` — 17 routes, no `register`, no `profile.destroy`
- `npm run build` — Vite build succeeds

**Not yet done**
- MySQL was not running, so the `atlas_netcare` database has not been created and no migration has been run against MySQL. Tests use in-memory SQLite, so they pass regardless. **Start MySQL from the XAMPP Control Panel before Stage 4.**

**Resolved same day**
- MySQL started. XAMPP ships **MariaDB 10.4.32**, not MySQL proper. Laravel 11+ has a dedicated `mariadb` driver that is more accurate than the generic `mysql` one currently in `.env` — worth switching before M7, because MariaDB implements `JSON` as `LONGTEXT` with a check constraint rather than a native type, and some JSON query methods behave differently. **Still to do.**
- GitHub CLI authenticated as `phone292025`. Private repository created at **https://github.com/phone292025/atlas-netcare**, `main` pushed and tracking `origin/main`.
- Collaborators **not** added yet, at the group's request. Decide whether the group leader should own the repository before inviting the other six.

**Next**
- Stage 2: `config/atlas.php`, the eleven enums, `EnsureUserHasRole` middleware.

### 2026-08-27 — Stage 2

**Done**
- `config/atlas.php` written to the specification in `05-architecture.md`, plus the navigation map from `08-ui-conventions.md`. Every value carries a comment explaining what it does and which document or open question it comes from.
- All **eleven enums** created in `app/Enums/`, matching the documented list exactly. Each has `label()`; every status enum also has `tone()` returning one of `healthy` / `attention` / `urgent` / `inactive`, which `<x-status-badge>` will map to Tailwind classes in one place (Stage 3).
- Shared `HasValues` trait giving every enum `values()` and `options()` — used by seeders, factories and select inputs. One file rather than 33 lines duplicated across eleven.
- `EnsureUserHasRole` middleware, registered as the `role` alias in `bootstrap/app.php`. Accepts a comma list: `role:engineer,admin`.
- `app/Http/Middleware/` had to be created — Laravel 12 ships no middleware directory by default.

**Provisional values marked in code**
- `atlas.thresholds` carries a PROVISIONAL comment pointing at Q1.
- `atlas.sla_hours` and `TicketStatus` carry PROVISIONAL comments pointing at Q3, and `TicketStatus` explicitly names what must *not* be built yet: the state machine, transition guards and SLA breach detection. Anyone opening that file sees the constraint.

**Verified**
- `php artisan test` — **93 passed, 341 assertions** (up from 22)
  - `EnumContractTest` — every case has a label, every backing value is lower snake_case, every status tone is one of the four known tones, `values()` and `options()` stay in step
  - `RiskBandTest` — banding pinned at every boundary, including that retuning `config` changes the outcome without touching code
  - `AtlasConfigTest` — risk weights sum to 1.0, bands are ordered, every priority has an SLA, more urgent priorities have shorter SLAs, every role has navigation
  - `EnsureUserHasRoleTest` — matching role passes, non-matching forbidden, multi-role lists work, no role forbidden, deactivated user forbidden, guests redirect to login rather than 403
- `php artisan about` — application boots with the new middleware alias registered

**Known limitation**
- `users.role` and `users.is_active` do not exist as columns yet; they arrive with the M1 migration in Stage 4. The middleware tests set them as in-memory attributes, which exercises the logic fully but is not the same as testing against the real schema. Re-check these tests once the migration lands.

**Deferred**
- `routes/api.php` is not registered in `bootstrap/app.php`. Laravel 12 omits it by default, and `php artisan install:api` would pull in Sanctum, which this project does not need — the dashboard polling endpoint is session-authenticated from the same browser. Decide in M5 whether to register `api.php` manually or put the endpoint in `web.php`.

**Next**
- Stage 3: layouts, role-filtered sidebar, shared Blade components.

### 2026-08-27 — Stage 2 review and fixes

An independent review of Stage 2 found a real defect. All findings were re-verified against the code before acting on any of them.

**Correctness — fixed**
- `EnsureUserHasRole` failed **open** on deactivated accounts. `$user->is_active === false` compares strictly against a value Eloquent returns as `int(0)` when no cast is configured, so the guard never fired and FR-106 was silently broken. Proven with a throwaway probe (`type: int, value: 0`), then fixed to `! ($user->is_active ?? true)`.
- The original test suite could not have caught it: it assigned a PHP `false` in memory, which makes strict comparison work. Two regression tests were added that load the user **back out of the database**, and were validated by reintroducing the bug — they fail against it and pass against the fix.

**Tooling — fixed**
- `@dataProvider` doc-comments replaced with `#[DataProvider]` attributes. Deprecated in PHPUnit 11.5.56, removed in 12. The warnings were printing on every run; earlier sessions missed them because the test output was grepped down to `PASS|FAIL|Tests:`. **Do not filter test output that aggressively.**
- `vendor/bin/pint` now passes clean. `config/atlas.php` had aligned `=>` arrows that the Laravel preset un-aligns, and `ProfileController` still carried an unused `Auth` import from Stage 1 — a `sed` that was reported as done had silently failed.
- A `pint.json` with `align_single_space_minimal` was tried and **rejected**: it forces alignment across the entire codebase including Laravel's own files. Recorded in the decision log.

**Design — improved**
- `HasLabel` and `HasTone` interfaces added under `app/Enums/Contracts/`. `HasValues::options()` calls `label()`; without an interface a twelfth enum that forgot it would fail at render time rather than statically.
- `EnumContractTest` now **discovers** enums by globbing `app/Enums/*.php` instead of a hand-maintained list, so a new enum is covered the moment it exists. It also asserts the discovered set still matches the eleven the docs promise. Note: data providers run before Laravel boots, so `app_path()` is unavailable — the glob walks up from `__DIR__`.

**Documentation — corrected**
- `05-architecture.md`: enum `Concerns/` and `Contracts/` added to the folder tree; `navigation` added to the config snippet; casts and Pint formatting noted.
- `ADR-0004`: corrected the claim that the schema has eleven enumerated fields. Eleven get an enum class; three more (`locations.type`, `checklist_items.category`, `telemetry_readings.source`) are enumerated in the schema but deliberately do not, because nothing branches on them. Added a warning that `telemetry_readings.source` (`simulated`/`live`) is **not** the same vocabulary as `atlas.telemetry_driver` (`simulated`/`snmp`).
- Minor: `RiskBand::fromScore()` docblock claimed scores were clamped; nothing clamps, the comparison chain handles out-of-range on its own. Redundant ternary removed from `AtlasConfigTest`.

**Verified**
- `php artisan test --display-deprecations` — **107 passed, 357 assertions, zero deprecations**
- `vendor/bin/pint --test` — passes
- Regression tests confirmed to fail against the reintroduced bug

**Lesson worth keeping**
Three separate `sed`/`perl` edits in this project have silently failed on backslashes in PHP namespaces and been reported as done. When editing PHP `use` statements from the shell, use `\x5c` for the backslash or an editor tool — and check the result, do not assume it applied.

### 2026-08-27 — Second review pass

A follow-up review found seven issues. All were verified against the code before acting; all seven were real.

**Corruption — fixed**
- `docs/INDEX.md` had been broken since Stage 0 (`c91325f`). A botched `perl` edit left three stray table rows above the file and glued `# Documentation Index` onto the end of line 3, so the H1 rendered as body text. This is the first file every agent and teammate is told to open, and it sat broken through two stages without anyone noticing. Repaired.

**Overclaiming — corrected**
- The middleware comment and commit message read as though FR-106 was satisfied. It is not: `LoginRequest::authenticate()` has **no** `is_active` check, and **no route currently uses the `role` middleware**, so a deactivated user can still sign in and reach everything that exists today. The guard fix was real; the traceability claim was premature. Comment rewritten, and FR-106 marked **partially built** in `03-requirements.md`.
- Two docblocks described code that does not exist, in the present tense: `atlas.php` said the driver is "Bound in AppServiceProvider" (that provider is empty) and `UserRole::homeRoute()` referred to `DashboardRedirect` (no such class). Both now say PLANNED and name the stage that will build them.

**Coordination risk — closed**
- Twelve route names were coined in `config/atlas.php` during Stage 2 and recorded nowhere else; `08-ui-conventions.md` documented only the assistant menu. With seven owners naming their own routes, and a sidebar that **silently skips unregistered routes**, a mismatch would make a menu item vanish with no error. All eighteen route names are now a documented table in `08-ui-conventions.md`, mapped to the owning module.

**Design tokens — settled before Stage 3 bakes them in**
- The tone `inactive` is renamed **`neutral`**. It has to cover both "out of play" (`retired`, `maintenance`) and "deliberately quiet" (`low` priority, `scheduled`); `inactive` was wrong for a low-priority ticket, which is very much active.
- `TicketStatus::Open` and `Reopened` were `urgent`, making every brand-new ticket render the same red as an offline access point. All outstanding ticket states are now `attention`. **Status carries progress; priority carries urgency.**
- `ShiftStatus::CheckedIn` was `attention` — a shift running normally is a good state. Now `healthy`.
- `AlarmType` keeps `HasTone` (severity in the notification list is genuinely useful), but the docs no longer describe tone as status-only, and `<x-status-badge>` is specced as accepting any `HasTone` enum.
- The design-token table in `08-ui-conventions.md` is now the authority and covers every case, so Stage 3 has nothing left to guess.

**Minor**
- `glob()` returns `false` on failure, which would have produced a `TypeError` instead of a clean assertion failure. Now `glob(...) ?: []`.
- `AGENTS.md` still claimed 93 tests. It is 107.

**Verified**
- `php artisan test --display-deprecations` — 107 passed, 357 assertions, zero deprecations
- `vendor/bin/pint --test` — passes
- All internal documentation links resolve

**Pattern worth noting**
Both review passes found defects that the test suite reported as passing, and this one found a corrupted file that had survived two stages of "verified" work. Green tests and a clean Pint run say nothing about whether the docs are true or the claims are honest. Read the artefacts.

### 2026-08-27 — Stage 3a: application shell

Stage 3 is the largest of the five, so it is split. **3a is the foundation every component and screen sits on**; 3b and 3c build the remaining component library on top of it.

**Done**
- `tailwind.config.js`: a `brand` (indigo) palette for links, primary buttons and active navigation — deliberately none of the four status hues, so a coloured button never reads as a status. Semantic colour is **not** defined here; see below.
- `<x-icon>`: 17 hand-built inline SVGs on one 24x24 / 1.5-stroke grid. No icon package — eleven navigation icons do not justify a dependency. An unknown name renders a visible "?" so a typo shows on screen instead of leaving a gap.
- `<x-badge>` — **the single place that turns a tone into classes.** `<x-status-badge>` and `<x-priority-pill>` both delegate to it, so `urgent` is one red everywhere. An unrecognised tone renders fuchsia on purpose: a bug should be visible, not silently grey.
- `<x-sidebar>`: role-filtered navigation built from `config('atlas.navigation')`. Active state matches the whole `admin.users.*` section so create/edit screens keep their parent highlighted.
- `layouts/app.blade.php`: full shell — fixed sidebar on desktop, Alpine slide-over drawer on mobile, sticky topbar, user block with role, sign-out. Replaces Breeze's top-nav.
- `DashboardRedirect` + `/home`: sends each role to its own landing screen, falling back to the profile page for routes that do not exist yet. This is the class `05-architecture.md` referenced and `UserRole::homeRoute()` was written for — both now real rather than PLANNED.
- Breeze leftovers removed: `layouts/navigation.blade.php` and `dashboard.blade.php`. The `dashboard` route is replaced by `home`; nine references updated.
- `profile/edit.blade.php` rebuilt — it had unbalanced `<div>`s and an empty card left behind when the delete-account form was removed in Stage 1.

**The tone → class mapping deliberately does not live in config**
Tailwind scans source files for class names. A class assembled at runtime from config (`bg-{$tone}-50`) is never seen by the scanner, gets purged from the build, and renders unstyled. So the mapping is literal strings inside `badge.blade.php`. This is the one place a "no magic values in code" instinct would produce a broken build.

**A layout bug that 120 passing tests did not catch**
The sidebar was written with `-translate-x-full` and `lg:translate-x-0`. Both utilities set `--tw-translate-x`, the negative one won the cascade, and **the sidebar sat off-screen on every desktop screen** — a 256px empty gutter with no navigation. Every test passed, because they assert on rendered HTML and cannot evaluate CSS.

Found by opening the page and measuring `getBoundingClientRect()`. Fixed by scoping the transform to `max-lg:`, so desktop has no transform at all. A regression test now pins the class names, since HTML assertions are the only lever available.

**Verified in a real browser**, not just in tests:
- Desktop 1280px — sidebar at `left: 0`, content offset 256px, no horizontal overflow
- Mobile 375px — drawer closed at `-256`, opens to `0`, closes back to `-256`, no horizontal overflow
- No console errors; Alpine 3.16.3 initialising correctly

> Measuring animated properties in this browser pane is unreliable — when the pane is not displayed the compositor pauses and CSS transitions never advance, so `getComputedStyle().transform` returns stale values. Remove the transition class before measuring position.

**Also done to make the app runnable**
- `atlas_netcare` database created; base Laravel migrations run against MariaDB
- A local `demo@atlas.test` user exists for visual checks, password `password`. Throwaway, local only, **not** tagged `is_demo`, and wiped by `migrate:fresh --seed` in Stage 4. Recreate with:
  ```
  php artisan tinker --execute='App\Models\User::firstOrCreate(["email" => "demo@atlas.test"], ["name" => "Demo Engineer", "password" => bcrypt("password")]);'
  ```
- `.claude/launch.json` added (gitignored) so the dev server starts with one command

**Verified**
- `php artisan test --display-deprecations` — **121 passed, 386 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- `npm run build` — succeeds; CSS 35 KB → 52 KB as the new component classes are picked up

**Next**
- Stage 3b: page-header, card, empty-state, alert, modal, data-table, pagination
- Stage 3c: stat-tile, risk-gauge, chart, timeline, form.input, form.select, form.textarea, checklist-item

### 2026-08-28 — Stage 3b: page furniture

The seven components every screen is assembled from. **121 → 155 tests.**

**Built**
- `<x-page-header>` — title, subtitle, actions slot. Renders an `<h2>`: the layout owns the only `<h1>`, and two of them leaves a screen reader unable to tell which is the page.
- `<x-card>` — title, subtitle, header and footer slots, plus `flush` for content that manages its own edges. A table inside a normal card is inset twice.
- `<x-empty-state>` — title, message, icon, action slot.
- `<x-alert>` — success / warning / error, optional title, optional dismiss.
- `<x-modal>` — replaces Breeze's, which nothing referenced. Its focus trap was kept because tab containment is fiddly and theirs is correct; the header, footer slot, close button and `role="dialog"` / `aria-modal` semantics are new.
- `<x-data-table>` — headers array, rows as plain `<tr>`, plus `filters`, `empty` and `pagination` slots. Sorting is a **link**, not JavaScript, so the controller orders the query and the sort survives a refresh, a back button and a shared URL.
- `<x-pagination>` — hand-rolled rather than `$paginator->links()`, whose stock Tailwind view uses its own greys and focus styles. Windowed to first / last / current ±1. Renders nothing on a single page.
- Nine icons added for the above — `check-circle`, `warning`, `x-circle`, `inbox`, `chevron-left/right/up`, `sort`, `plus`. **17 → 26.**

**The tone mapping moved, and had to**
`badge.blade.php` held the tone → class `match` because a badge was the only surface that needed it. An alert banner cannot reuse a badge's classes — different size, border, tinted icon — so the vocabulary moved to **`app/Support/Tone.php`** with one method per surface (`badge()`, `banner()`, `accent()`). `<x-risk-gauge>` in 3c is the third surface, and `<x-chart>` will need the same colours as JavaScript values, which Tailwind cannot supply at all.

That required `./app/**/*.php` in the Tailwind `content` array. **Verified in the built CSS** rather than assumed: all fourteen tone classes, including four that exist only in `Tone.php`, are present after a clean build. Delete that content line and every badge and alert in the system silently loses its colour.

`ToneTest` pins the property rather than the palette — badge, banner and accent must agree on the colour family for a given tone — so a deliberate recolour stays a one-line edit but a drifting one fails.

**Flash messages are the layout's job now**
The shell was carrying a hand-written emerald `<div>` for `session('status')`. It now renders `<x-alert>` for four keys — `status`, `success`, `warning`, `error`. Seven owners each inventing a banner was a guaranteed inconsistency.

**Two traps worth knowing**
- `ComponentSlot::isEmpty()` compares against `''` **exactly**, and a `@foreach` over an empty collection still emits the whitespace between its tags. The slot is blank on screen but not an empty string, so `<x-data-table>` trims before deciding. Without that, an empty list renders a table header sitting above nothing. There is a test for it.
- `<x-pagination>` needs the controller to call `->withQueryString()`. Without it, paging away from page 1 drops the filters and the sort the user just chose and the list appears to reset itself. The component deliberately does **not** do this itself — it would be mutating its caller's object as a side effect of rendering.

**Stale classes after deleting a view** *(cause corrected during the Stage 3b review — see below)*
Removing the scratch gallery and rebuilding produced a byte-identical CSS, still carrying `grid-cols-2` that no source used any more. The observation was real; the diagnosis was not. See the review entry below.

**Verified**
- `php artisan test --display-deprecations` — **155 passed, 496 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- `npm run build` from a cleared cache — 41.83 KB CSS
- `php artisan view:cache` — every template compiles

**Verified in a real browser**, desktop and mobile, on a scratch gallery page rendering all seven side by side (deleted before committing):
- Desktop — sidebar at `left: 0`, two-column card grid, sort indicators, pagination window `1 2 3 4 … 44`, no horizontal page overflow, no console output at all
- **375px** — drawer closed at `-256`, hamburger present, cards single column, page-header actions stacked below the title, and the table scrolling **inside its own container** (324px visible, 632px content) with the page body not moving sideways
- Modal — centred, backdrop dimming, header, footer; confirmed by rendering it with `:show="true"`

> **The window resize tool does not work in this browser pane** — it reports success and the viewport stays 1707px. Mobile was checked by injecting a 375px same-origin `<iframe>` of the page, whose media queries respond to the iframe viewport. Use that method for every future responsive check.

> Extending Stage 3a's note on this pane: Alpine's `x-transition` **never advances** here. Opening the modal by event left `show === true`, the body scroll-locked, the wrapper `display: block` — and the dialog frozen at the enter-*start* state (`display: none; opacity: 0; scale(.95)`). Synchronous watchers fire; anything driven by `requestAnimationFrame` does not. To check an animated component, render it in its open state instead of transitioning into it.

**Next**
- Stage 3c: stat-tile, risk-gauge, chart, timeline, form.input, form.select, form.textarea, checklist-item

### 2026-08-28 — Stage 3b review

Independent review of Stage 3b. Everything was re-verified against the code and in a browser rather than taken from the notes.

**Verdict: the work is sound.** One documented cause was wrong, one measurement does not reproduce, and one decision is now backed by a number instead of an assertion. No defects found in the components themselves.

**Verified independently**
- `php artisan test --display-deprecations` — 155 passed, 496 assertions, zero deprecations
- `vendor/bin/pint --test` — passes
- Every Blade template compiles
- Rendered all seven components on a scratch page at 1280px and at mobile width, then deleted the scaffold

What the browser confirmed, beyond what the tests assert:
- All four alert tones render with the right colour and icon; an unrecognised type renders **fuchsia**, so the visible-bug guard works end to end
- `TicketStatus::Open` renders amber and `TicketPriority::Low` renders slate — the tone corrections agreed before Stage 3 held all the way to the screen
- Sorting: `aria-sort="ascending"` appears on the sorted column only, its link toggles to `desc`, the other column stays `asc`, and `page` is dropped from both. Sorting from page 3 does not strand you on page 3 of a re-sorted list.
- Pagination range maths correct — "Showing 21 to 30 of 47"
- Desktop sidebar at `left: 0` with content offset 256; the Stage 3a cascade fix survived 3b
- No horizontal page overflow at either width; the wide table scrolls inside its own container

**Correction: the CSS size claim was misattributed**
The Stage 3b notes blamed Vite for retaining classes from deleted files and prescribed `rm -rf node_modules/.vite public/build`. That is not the cause. Both directories were cleared in each measurement below and the difference persisted:

| Same source files | CSS |
|---|---|
| `php artisan view:clear` first | **42,191 bytes** |
| `php artisan view:cache` first | **54,035 bytes** |

The real cause is `./storage/framework/views/*.php` in the Tailwind `content` array — a Laravel default that makes **compiled Blade views count as source**. So build output depends on view-cache state, and a deleted view keeps contributing classes until its compiled artifact is cleared. The fix is `php artisan view:clear` before `npm run build`. Decision log updated; the superseded entry is struck through rather than deleted.

The recorded "41.8 KB actual" also does not reproduce as an unconditional figure — it is the *views-cleared* number. Quote it as such in the report, with the condition attached.

**Confirmed: `./app/**/*.php` in the content array is the right call**
This looked over-broad, so it was measured. Narrowing to `./app/Support/*.php` produced 53,612 bytes against 53,675 — a **63 byte** difference, with all `Tone` classes still present either way. The broad glob costs almost nothing, and its failure mode (63 bytes of unused CSS) is far cheaper than the narrow one's: a future file outside `app/Support` holding class strings would be silently purged, which is the exact bug the path exists to prevent. Keep it broad.

**Notes on the components, none blocking**
- `<x-alert>` wraps every alert in `x-data`/`x-show` even when not dismissible. Harmless, and it fails safe if Alpine never loads, but the Alpine binding is only needed for the dismissible case.
- The unknown-type branch asks `<x-icon>` for `help`, which no case defines. It falls through to the "?" fallback, which is the intended outcome — worth knowing it is reached by fallback rather than by a defined case.

**Still outstanding, unchanged by this review**
- Q3 and Q6 remain `Open`
- Design freeze is **15 Sep**. Context Diagram, DFD 0 and the consolidated ERD are worth 15 marks and none exist yet.

### 2026-08-28 — Stage 3c: dashboards, forms and the inspection control

Stage 3 is complete. The component library is built; every module screen from here is assembled from it rather than written from scratch.

**Done — eight components**
- `<x-stat-tile>` — a headline number with an optional delta. **Tone is the caller's decision, never inferred from the trend.** Rising uptime is good and rising latency is bad; a component that guessed would get half a dashboard wrong. `trend` picks the arrow, `tone` picks the colour, independently.
- `<x-risk-gauge>` — a 0-100 score with its band, clamped so an out-of-range score cannot overflow its track. `role="meter"` rather than a decorative bar. Derives the band from the score when one is not supplied. **Renders the contributing factors sorted heaviest first** — a bare number is not analytics, and the factors are the whole reason ADR-0007 chose a weighted model over a trained one.
- `<x-chart>` — Chart.js wrapper. Height is explicit because `maintainAspectRatio` is false, so a container without one collapses and the chart silently does not appear. Accepts a `chart-data` window event so the 20-second dashboard poll can animate between states instead of replacing the canvas.
- `<x-timeline>` — who did what, when. Absolute timestamp in `datetime` and `title`, relative time as the text.
- `<x-form.input>`, `<x-form.select>`, `<x-form.textarea>` — plus an internal `<x-form.field>` wrapper the three share, so the label position, error treatment and aria wiring cannot drift apart.
- `<x-checklist-item>` — OK / Issue / N/A with a remark that appears only for Issue.

**Three new Tone surfaces**
`solid()` for gauge fills, `choice()` for `peer-checked:` states, `chart()` for hex — a canvas cannot use a Tailwind class, so that one surface leaves the class vocabulary while staying in the single source.

**Two traps hit and recorded**
- A `str_replace` was used to prefix `Tone::badge()` with `peer-checked:`. That assembles a class at runtime, Tailwind never sees it, and every selected choice on the inspection form would have rendered colourless. Replaced with `Tone::choice()`, written out in full. **This is the exact trap the docs warn about, walked into anyway.**
- A Blade comment was nested inside a Blade comment in the risk-gauge docblock. The inner closing token ended the outer one, so the usage example below it became live template code and threw at render. Both are in the decision log.

**Verified in a browser at both widths, not only in tests**
- Desktop 1280px — stat tiles, gauge with factors ordered correctly, Chart.js line taking its amber from `Tone::chart()`, form controls with required marker and placeholder, timeline with tone-coloured dots
- **Mobile 375px — checklist tap targets measured at 44px tall**, which is the accepted minimum touch target. No horizontal overflow. Tapping Issue reveals the remark; the other items stay collapsed.
- That mobile measurement is the evidence for **NFR-05**, and it is reproducible during UAT.

> A screenshot mid-review appeared to show two cards missing. They were present in the DOM at the correct position — the pane had served a stale frame, the same non-compositing artifact noted in Stage 3a. **Measure the DOM before believing a screenshot.**

**Verified**
- `php artisan test --display-deprecations` — **205 passed, 622 assertions**, zero deprecations
- `vendor/bin/pint --test` — passes
- Clean build (`view:clear` first, per the corrected guidance) — CSS **47,333 bytes**

**Next**
- Stage 4: foundation migrations (`users`, `locations`, `lab_pcs`, `access_points`), `ReferenceSeeder`, empty `DemoDataSeeder`. That completes Sprint 0.
- Still open and unchanged: Q3, Q6, and the 15 Sep design freeze with Context Diagram, DFD 0 and the consolidated ERD outstanding.
