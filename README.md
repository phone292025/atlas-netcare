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

**Phase 2 — System Design**, with Sprint 0 scaffolding running in parallel. Laravel 12 + Breeze are installed; feature modules have not started. Design freeze: **15 September 2026**.

Current build state: [docs/progress/build-log.md](docs/progress/build-log.md).

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
| Email | `ATLAS_ADMIN_EMAIL` (default `admin@atlas.local`) |
| Password | `ATLAS_ADMIN_PASSWORD` |

**Set your own password in `.env` before seeding**, and change it again after signing in — the account is flagged `must_change_password`. Every other real account is created from the admin's user-management screen.

### Demo accounts

While `ATLAS_DEMO_MODE=true`, seeding also creates one sign-in per role. Most of the built screens belong to the assistant and the engineer, so without these there is no way to open the inspection form or the ticket queue at all.

| Role | Email | Password |
|---|---|---|
| Network Engineer | `yar@apu.test` | `ATLAS_DEMO_PASSWORD` |
| Student IT Assistant | `swan@apu.test` | `ATLAS_DEMO_PASSWORD` |
| Student IT Assistant | `nyan@apu.test` | `ATLAS_DEMO_PASSWORD` |

All three share one password — they exist to be handed round the team, and per-account passwords would just be three things to lose. Two assistants on purpose: one cannot demonstrate that an assistant sees only *their own* inspections and tickets.

**Forgotten or changed one?** Re-run the seeder — it resets them rather than failing:

```bash
php artisan db:seed --class=DemoDataSeeder
```

These accounts are tagged `is_demo`, so `php artisan atlas:purge-demo-data` removes them and leaves the real administrator. With `ATLAS_DEMO_MODE=false` they are never created — a deployed system gets the administrator and nothing else.

> `ATLAS_DEMO_MODE=true` in `.env` seeds demonstration data. Set it to `false` for a clean system, and run `php artisan atlas:purge-demo-data` to remove data already seeded. See [docs/07-data-strategy.md](docs/07-data-strategy.md).

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
