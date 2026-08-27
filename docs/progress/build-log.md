# Build Log

> **Last updated:** 2026-08-27
>
> **Read this first in any new development session.** It is the handover between sessions: what exists, what is half-done, and what is next. Conversation history does not survive; this file does.

---

## Where the build is right now

| | |
|---|---|
| **Stage** | Sprint 0 — scaffolding |
| **Last session** | 2026-08-27 |
| **Laravel installed** | ✅ 12.68.0, Breeze Blade stack |
| **Database created** | ❌ Not yet — MySQL was not running |
| **Next action** | Stage 3: layouts, role-filtered sidebar, shared Blade components |

## Sprint 0 stages

Sprint 0 is broken into five stages so each can be reviewed before the next starts. A stage is not done until its checkbox is ticked here.

| # | Stage | Status |
|---|---|---|
| 0 | Repository, gitignore, branch, docs committed | ✅ Done 2026-08-27 |
| 1 | Laravel install, `.env`, Breeze with register removed, Vite + Tailwind + Alpine + Chart.js | ✅ Done 2026-08-27 |
| 2 | `config/atlas.php`, all eleven enums, `EnsureUserHasRole` middleware | ✅ Done 2026-08-27 |
| 3 | Layouts, role-filtered sidebar, shared Blade components | ⬜ Not started |
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

Newest first. One entry per development session: what was done, what was decided, what is next.

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
