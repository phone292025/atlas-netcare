# Atlas NetCare

Campus network monitoring and IT operations portal — capstone project for **AAPP011-4-2**, Asia Pacific University of Technology & Innovation.

**Group Atlas** · Intakes UCDF2411ICT(SE), UCDF2411ICT(ITR) · Supervisor: Mr Dhason Padmakumar

---

## What it does

Atlas NetCare replaces the fragmented, manual workflows APU's IT assistants and network engineers currently rely on with one role-based portal:

- **Live Wi-Fi telemetry** — access point health, latency, client load and congestion across the Library, IT Labs and Cafeteria
- **Digital lab inspections** — mobile checklists that replace paper logs during physical lab rounds
- **Incident ticketing** — one traceable queue from an assistant's report to an engineer's resolution
- **Duty rosters and alarms** — shift scheduling, inspection reminders and handover tracking
- **Predictive maintenance** — risk scoring that flags equipment likely to fail before it does

## Stack

Laravel · Blade · Tailwind CSS · Alpine.js · Chart.js · MySQL

## Status

**All seven modules are built** (M1–M7) on Laravel 12 + Breeze, and the suite runs on both SQLite and MariaDB in CI.

The four whole-system diagrams are drawn: [docs/12-system-diagrams.md](docs/12-system-diagrams.md). What is left is the written report and the per-member artefacts, tracked in [docs/progress/what-is-left.md](docs/progress/what-is-left.md).

Current build state, including the test count: [docs/progress/build-log.md](docs/progress/build-log.md).

## Documentation

All project knowledge lives in [`docs/`](docs/). Start at **[docs/INDEX.md](docs/INDEX.md)**.

Working on the code? Read **[AGENTS.md](AGENTS.md)** first — it is the short version of the rules, and coding agents load it automatically.

## Getting started

### Prerequisites

| Tool | Version | Notes |
|---|---|---|
| XAMPP | PHP 8.2+ | Provides PHP and MySQL. Laravel 12 needs PHP `^8.2` — see [ADR-0008](docs/decisions/ADR-0008-laravel-12-pinned.md). |
| Composer | 2.x | [getcomposer.org/download](https://getcomposer.org/download/) — the Windows installer finds XAMPP automatically |
| Node.js | 20+ | For Vite |
| Git | any recent | |

### Setup

```bash
git clone https://github.com/phone292025/atlas-netcare.git CP-Project
cd CP-Project

composer install          # never `composer update` — see ADR-0008
npm install

cp .env.example .env
php artisan key:generate
```

Start **Apache and MySQL** from the XAMPP Control Panel, then create the database:

```bash
mysql -u root -e "CREATE DATABASE atlas_netcare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Then migrate, seed and run:

```bash
php artisan migrate --seed
npm run dev               # keep this running in its own terminal
php artisan serve         # in a second terminal
```

The app is at `http://127.0.0.1:8000`.

### Signing in

Atlas NetCare has **no self-registration** — an administrator creates every account. `php artisan migrate --seed` creates the first one from your `.env`:

| | |
|---|---|
| Email | `admin@atlas.local` |
| Password | whatever you set as `ATLAS_ADMIN_PASSWORD` |

**`.env.example` ships that blank on purpose**, and the seeder refuses to create the administrator until you set it. It used to ship `ChangeMeNow!2026`, which put the highest privilege account in the system behind a password printed in a public repository.

> **The administrator does not share the demo password.** `ATLAS_ADMIN_PASSWORD` and `ATLAS_DEMO_PASSWORD` are different values, and typing the demo one at the admin login gives "These credentials do not match our records" with nothing to explain why. If a sign-in fails, check which of the two the account uses before assuming the account is broken.

**Set your own password in `.env` before seeding.** The account is flagged `must_change_password`, so the first sign-in lands on the profile screen and every other page redirects back there until you choose a new one.

Re-running `migrate --seed` after that is safe: since 2026-09-04 the seeder leaves an existing administrator's password and flag alone. It used to overwrite both on every run, which silently undid the password you had just chosen. Role, activation and the soft delete **are** still restored, so re-seeding remains the way back in if you lock yourself out. To reset a forgotten administrator password, use the reset link rather than a seeder.

Every other real account is created from the admin's user-management screen.

### Demo accounts

While `ATLAS_DEMO_MODE=true`, seeding also creates one sign-in per role. Most of the built screens belong to the assistant and the engineer, so without these there is no way to open the inspection form or the ticket queue at all.

| Role | Email | Password |
|---|---|---|
| Network Engineer | `engineer@atlas.local` | `AtlasDemo!2026` |
| Student IT Assistant | `assistant.one@atlas.local` | `AtlasDemo!2026` |
| Student IT Assistant | `assistant.two@atlas.local` | `AtlasDemo!2026` |

Those are the values a fresh clone gets from `.env.example`. They are **login identifiers only**. Nothing is sent to them and they never have to be deliverable, so you can sign in without owning any mailbox.

### If you want the password reset to actually deliver

FR-102 sends a reset link by email, and `@atlas.local` can never receive one. To demonstrate it, point the four addresses at a real mailbox instead. Gmail ignores everything after a `+`, so one account covers all four and nobody has to share a personal address:

```
ATLAS_MAIL_ACCOUNT=your.gmail.name
ATLAS_ADMIN_EMAIL="${ATLAS_MAIL_ACCOUNT}+admin@gmail.com"
ATLAS_DEMO_ENGINEER_EMAIL="${ATLAS_MAIL_ACCOUNT}+engineer@gmail.com"
```

The example file ships the `@atlas.local` versions on purpose. Deriving the login from a Gmail account meant anybody cloning this saw `your.gmail.name+admin@gmail.com` on the sign-in screen, which works and reads like a mistake. See [Q11](docs/02-open-questions.md#q11).

> **Changing one of these on a database that is already seeded gives you a second account, not a renamed one.** Both seeders match on email, so the old row keeps its tickets and shifts and you sign in to an empty copy. Rename the existing rows instead.

All three share one password — they exist to be handed round the team, and per-account passwords would just be three things to lose. Two assistants on purpose: one cannot demonstrate that an assistant sees only *their own* inspections and tickets.

**Forgotten or changed one?** Re-run the seeder — it resets them rather than failing:

```bash
php artisan db:seed --class=DemoDataSeeder
```

These accounts are tagged `is_demo`. With `ATLAS_DEMO_MODE=false` they are never created — a deployed system gets the administrator and nothing else.

Remove them with `php artisan atlas:purge-demo-data`, which also takes `--dry-run` to show what would go. It refuses if a real record points at a demonstration asset, rather than leaving it dangling.


> `ATLAS_DEMO_MODE=true` in `.env` seeds demonstration data. Set it to `false` for a clean system. See [docs/05-data-strategy.md](docs/05-data-strategy.md).

### Email

FR-102 lets a user request a password reset link **by email**, which is only a real feature if a message arrives. Set it up once:

1. Your Google account needs **2-Step Verification** on. Then **Google Account → Security → App passwords**, create one named `Atlas NetCare`, and copy the 16 characters.
2. In `.env`, set `ATLAS_MAIL_ACCOUNT` to your Gmail account name **without** `@gmail.com`, and paste the app password into `MAIL_PASSWORD`. **Remove the spaces Google displays it with.**
3. Check it:

```bash
php artisan atlas:mail-test
```

That sends one real message and, if the server refuses it, reads the error and tells you which of the usual three causes it is. A green test suite proves nothing here — the suite fakes the mailer, so this command is the only thing that can tell you mail works.

**The app password is a key to your Google account.** `.env` is gitignored and must stay that way; `.env.example` has the shape but no value. If one is ever committed, revoke it on that same App passwords screen — rewriting git history does not help, because the push already happened.

Leaving `MAIL_PASSWORD` empty is supported. The reset screen detects it and says so, rather than claiming to have sent something. Nobody has to configure mail to work on the rest of the system.

### Seeing the dashboard with data


The AP health dashboard is driven by telemetry, and telemetry only exists once something has collected it. Two commands:

```bash
php artisan atlas:backfill-telemetry --days=14
```

Invents two weeks of plausible history and rolls it into the hourly buckets the charts read — so the 24-hour chart has something to draw immediately, instead of after you have left the collector running for a day.

```bash
php artisan schedule:work
```

Keeps it live: one reading per access point per minute, aggregated hourly, pruned weekly. **Without the scheduler the dashboard shows whatever the last collection produced and quietly goes stale**, which looks exactly like a broken dashboard.

Telemetry is simulated by default (`ATLAS_TELEMETRY_DRIVER=simulated`). Switching to real access points is that one `.env` value plus implementing `SnmpTelemetrySource` — nothing else in the system changes. See [ADR-0002](docs/decisions/ADR-0002-telemetry-source-driver.md).

## Team

| Member | TP Number | Module |
|---|---|---|
| Yar Zar Min *(Group Leader)* | TP086455 | *to be assigned* |
| Swan Yee Htet | TP085967 | *to be assigned* |
| Thint Myat Htet Myat Shwe | TP086389 | *to be assigned* |
| Phone Myat Ko | TP086905 | *to be assigned* |
| Nyan Lin Tun | TP086590 | *to be assigned* |
| Sachin Nair A/L C. Suthan | TP087180 | *to be assigned* |
| Kaung Khant Zin | TP086063 | *to be assigned* |

Assign modules in [`docs/modules/README.md`](docs/modules/README.md), then mirror the assignment here and in the Workload Matrix.
