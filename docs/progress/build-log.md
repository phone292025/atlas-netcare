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
| **Laravel installed** | ❌ Not yet — blocked on Composer |
| **Database created** | ❌ Not yet |
| **Next action** | Install Composer, then Stage 1 |

## Sprint 0 stages

Sprint 0 is broken into five stages so each can be reviewed before the next starts. A stage is not done until its checkbox is ticked here.

| # | Stage | Status |
|---|---|---|
| 0 | Repository, gitignore, branch, docs committed | ✅ Done 2026-08-27 |
| 1 | Laravel install, `.env`, Breeze with register removed, Vite + Tailwind + Alpine + Chart.js | ⏸ Blocked — Composer not installed |
| 2 | `config/atlas.php`, all eleven enums, `EnsureUserHasRole` middleware | ⬜ Not started |
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
- `.gitignore` (Laravel-aware) and `.gitattributes` added
- All 35 documentation files committed as the initial commit
- Branch renamed `master` → `main` to match the docs
- This build log created

**Found**
- Composer is not installed on the development machine. Stage 1 cannot start without it.
- GitHub CLI is not installed, so the remote repository has to be created through the website or after installing `gh`.

**Decided**
- Sprint 0 split into five reviewable stages, recorded above.

**Next**
- Install Composer, then Stage 1.

**Still open**
- Q3 (ticket lifecycle) and Q6 (account creation) remain `Open`. Q6 affects Stage 1 — the working assumption is Breeze with the `register` route removed. Q3 affects nothing in Sprint 0, since `tickets` is not a foundation table.
