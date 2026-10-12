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

Everything listed above is built, on Laravel 12 with the Laravel Breeze starter kit.

The suite has 1,019 tests, and CI runs it on SQLite and MariaDB. On MariaDB all of them pass. On SQLite one test that needs MariaDB skips itself. A copy without the `docs` folder also skips the seven tests that check it.

## Getting started

### What to install

| Tool | Version | Notes |
|---|---|---|
| XAMPP | PHP 8.2 or newer | Gives you PHP and the database. XAMPP labels the database MySQL, but it is MariaDB. The project stays on Laravel 12 because Laravel 13 needs PHP 8.3. |
| Composer | 2.x | Get the Windows installer from [getcomposer.org/download](https://getcomposer.org/download/). Point it at `C:\xampp\php\php.exe` when it asks, and let it add PHP to your PATH. |
| Node.js | 22 LTS, or 20.19 or newer | Vite 7 builds the front end and refuses older versions. |
| Git | any recent | Only needed to clone. An unzipped copy of the project doesn't need it. |

### Setup

Open a terminal, PowerShell or Git Bash, in the project folder. With access to the GitHub repository you can clone it instead:

```bash
git clone https://github.com/phone292025/atlas-netcare.git CP-Project
cd CP-Project
```

**1. Install the packages.**

```bash
composer install          # never composer update, see below
npm install
npm run build
```

Use `composer install`, never `composer update`. `composer.lock` pins every package, so each teammate gets the same versions. `update` rewrites the lock with whatever is newest on your machine, and everyone else's install changes with it.

**2. Create your settings file.**

```bash
cp .env.example .env      # in cmd: copy .env.example .env
php artisan key:generate
```

**3. Build the database.** Start **MySQL** in the XAMPP Control Panel. Apache isn't needed. Then run:

```bash
php artisan migrate --seed
```

The first time, it says the `atlas_netcare` database does not exist and asks whether to create it. Answer yes. Seeding then fills the system with demonstration data, risk scores included, in a few seconds.

**4. Run it** in two terminals:

```bash
php artisan serve           # the app, at http://127.0.0.1:8000
php artisan schedule:work   # keeps the Network Monitor live
```

The app opens without the scheduler, but the Network Monitor then shows every access point as Unknown and no alarms reach the bell. [Keeping the monitor live](#keeping-the-monitor-live) explains what the scheduler does.

When you change the front end, run `npm run dev` in a third terminal. It rebuilds on every save. Run `npm run build` again when you finish.

### If something goes wrong

- **`php` is not recognized.** Close and reopen the terminal after installing Composer. If it still fails, add `C:\xampp\php` to your PATH.
- **Composer says a PHP extension is missing.** Open `C:\xampp\php\php.ini`, delete the `;` at the start of that extension's `extension=` line, save, and run `composer install` again.
- **`SQLSTATE[HY000] [2002]` during `migrate`.** MySQL isn't running. Start it in the XAMPP Control Panel and run the command again.

### Running the tests

```bash
php artisan test
```

The tests use an in-memory SQLite database, so they need nothing running and never touch `atlas_netcare`.

### Signing in

Atlas NetCare has **no self-registration** — an administrator creates every account. `php artisan migrate --seed` creates the first one from your `.env`:

| | |
|---|---|
| Email | `admin@atlas.local` |
| Password | `AtlasAdmin!2026` |

That is the starting password a fresh clone gets from `.env.example` (`ATLAS_ADMIN_PASSWORD`). **It only works once:** the account is flagged `must_change_password`, so the first sign-in lands on the profile screen and every other page redirects back there until you choose your own.

> **The administrator does not share the demo password.** The administrator starts on `AtlasAdmin!2026`; the demo accounts below use `AtlasDemo!2026`. Typing one at the other's login gives "These credentials do not match our records" with nothing to explain why.

> **This password is public too: it is printed right here.** Before the app is reachable from anywhere but your own machine, change `ATLAS_ADMIN_PASSWORD` in `.env` before seeding, or sign in and change it straight away.

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

> **That password is public: it is printed right here.** Before the app is reachable from anywhere but your own machine (`php artisan serve --host=0.0.0.0` on campus Wi-Fi counts), set your own `ATLAS_DEMO_PASSWORD` and re-run `php artisan db:seed`, or remove the demo accounts with `php artisan atlas:purge-demo-data`.

### If you want the password reset to actually deliver

FR-102 sends a reset link by email, and `@atlas.local` can never receive one. To demonstrate it, point the four addresses at a real mailbox instead. Gmail ignores everything after a `+`, so one account covers all four and nobody has to share a personal address:

```
ATLAS_MAIL_ACCOUNT=your.gmail.name
ATLAS_ADMIN_EMAIL="${ATLAS_MAIL_ACCOUNT}+admin@gmail.com"
ATLAS_DEMO_ENGINEER_EMAIL="${ATLAS_MAIL_ACCOUNT}+engineer@gmail.com"
```

The example file ships the `@atlas.local` versions on purpose. Deriving the login from a Gmail account meant anybody cloning this saw `your.gmail.name+admin@gmail.com` on the sign-in screen, which works and reads like a mistake.

> **Changing one of these on a database that is already seeded gives you a second account, not a renamed one.** Both seeders match on email, so the old row keeps its tickets and shifts and you sign in to an empty copy. Rename the existing rows instead.

All three share one password — they exist to be handed round the team, and per-account passwords would just be three things to lose. Two assistants on purpose: one cannot demonstrate that an assistant sees only *their own* inspections and tickets.

**Forgotten or changed one?** Re-run the seeder — it resets them rather than failing:

```bash
php artisan db:seed --class=DemoDataSeeder
```

These accounts are tagged `is_demo`. With `ATLAS_DEMO_MODE=false` they are never created — a deployed system gets the administrator and nothing else.

Remove them with `php artisan atlas:purge-demo-data`, which also takes `--dry-run` to show what would go. It refuses if a real record points at a demonstration asset, rather than leaving it dangling.


> `ATLAS_DEMO_MODE=true` in `.env` seeds demonstration data. Set it to `false` for a clean system.

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

### Keeping the monitor live

```bash
php artisan schedule:work
```

Leave this running in its own terminal. It runs seven jobs on a timer:

| Job | When | What it does |
|---|---|---|
| `atlas:collect-telemetry` | every minute | Takes one reading per access point. One that has been silent for 5 minutes goes offline and gets a ticket |
| `atlas:dispatch-alarms` | every minute | Sends the alarms that have come due |
| `atlas:mark-missed-shifts` | every 5 minutes | Marks a shift missed when it has ended and nobody checked in |
| `atlas:aggregate-telemetry` | hourly | Rolls the last 3 hours of readings into the hourly chart data |
| `atlas:recalculate-risk` | daily at 02:00 | Rescores every asset and keeps the old scores |
| `atlas:generate-shifts` | Sundays at 18:00 | Builds next week's roster |
| `atlas:prune-telemetry` | Sundays at 03:15 | Deletes raw readings older than 90 days and hourly data older than 12 months |

The times are campus time.

Without the scheduler nothing looks broken at first. Five minutes after the last reading, every access point on the Network Monitor reads Unknown, and no new alarms reach the bell.

Turning it back on repairs the charts by itself. Its first round sees the silence and fills it with simulated readings, up to the last 24 hours, so the Network Monitor comes back to a full day instead of a single point.

The charts don't need it to start with. `migrate --seed` writes two weeks of hourly history, with the last day as readings, so the Network Monitor opens on a full day. Three access points' latency and packet loss climb over the fortnight. Those three are what fill the risk model's High band.

> **Don't run `atlas:backfill-telemetry` after seeding demo data.** It writes steady readings into every hour it covers that has none and re-aggregates them, so a two-week backfill flattens those three trends. Use it only for access points with no history.

Telemetry is simulated by default, with `ATLAS_TELEMETRY_DRIVER=simulated`. Real access points need that `.env` value changed and `SnmpTelemetrySource` implemented. Nothing else in the system changes.

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

When the group assigns modules, fill in the table above and the Workload Matrix.
