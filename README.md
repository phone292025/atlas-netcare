# Atlas NetCare

Campus network monitoring and IT operations portal. It began as a university capstone project and is now being upgraded to 2.0, which watches a real network instead of a simulated one.

---

## What it does

Atlas NetCare replaces the fragmented, manual workflows APU's IT assistants and network engineers currently rely on with one role-based portal:

- **Live Wi-Fi telemetry** — access point health, latency, client load and congestion across the Library, IT Labs and Cafeteria
- **Digital lab inspections** — mobile checklists that replace paper logs during physical lab rounds
- **Incident ticketing** — one traceable queue from an assistant's report to an engineer's resolution
- **Duty rosters and alarms** — shift scheduling, inspection reminders and handover tracking
- **Predictive maintenance** — risk scoring that flags equipment likely to fail before it does

## Stack

Laravel · Blade · Tailwind CSS · Alpine.js · Chart.js · PostgreSQL with TimescaleDB · Docker

## Status

Everything listed above is built, on Laravel 12 with the Laravel Breeze starter kit.

The suite has 1,033 tests, and CI runs it on SQLite and on PostgreSQL with TimescaleDB. One test checks a MariaDB trap and skips itself on both. A copy without the `docs` folder also skips the seven tests that check it.

## Getting started

Atlas runs as a Docker stack: the web app, a background worker, the scheduler and a PostgreSQL database, started together with one command.

### What to install

| Tool | Version | Notes |
|---|---|---|
| Docker Desktop | any recent | On Windows it runs on WSL 2, which the installer sets up. Nothing else is needed to run Atlas |
| Git | any recent | Only needed to clone. An unzipped copy of the project doesn't need it |

To work on the code as well, see [Working on the code](#working-on-the-code) below.

### Setup

Open a terminal, PowerShell or Git Bash, and start Docker Desktop. Then clone the project:

```bash
git clone https://github.com/phone292025/atlas-netcare.git CP-Project
cd CP-Project
```

**1. Create your settings file.**

```bash
cp .env.example .env      # in cmd: copy .env.example .env
```

**2. Start the stack.**

```bash
docker compose up -d --build
```

The first build takes a few minutes, because it downloads PHP, the PHP packages and the front-end tools. After that the app creates its key and builds the database on its own. It's ready when this shows `app` as `healthy`:

```bash
docker compose ps
```

**3. Load the demonstration data.** It takes a few seconds and includes two weeks of telemetry and the risk scores:

```bash
docker compose exec app php artisan db:seed
```

**4. Create your administrator.** It prints a one-time password, which you replace at the first sign-in. [Signing in](#signing-in) explains why there is no default one.

```bash
docker compose exec app php artisan atlas:create-admin
```

**5. Open [http://localhost:8000](http://localhost:8000) and sign in.** The scheduler is already running inside the stack, so the Network Monitor stays live.

### Day to day

| To | Run |
|---|---|
| Stop everything, keeping the data | `docker compose stop` |
| Start again | `docker compose up -d` |
| Update after a `git pull` | `docker compose up -d --build` |
| Follow the app's log | `docker compose logs -f app` |
| Run an artisan command | `docker compose exec app php artisan <command>` |

The database lives in a Docker volume, so it survives stopping and rebuilding. `docker compose down -v` deletes that volume and every row in it.

### Backups

```bash
docker compose exec db sh atlas/backup.sh
```

This writes `backups/atlas-<date>-<time>Z.dump`, and Atlas keeps running meanwhile. A backup holds every account's email address and password hash, so keep the `backups` folder private. Git ignores it. pg_dump warns about circular foreign keys on every backup. They are in TimescaleDB's own catalog, and the warning matters only to data-only dumps.

A backup is worth something only if it restores. To check one, restore it into a scratch database, which is compared with the file and then dropped:

```bash
docker compose exec db sh atlas/restore.sh backups/atlas-20261022-143529Z.dump --check
```

To bring a backup back, restore it into a new database, then swap it in while Atlas is stopped. The database it replaces is kept as `atlas_before_<time>` until you drop it.

```bash
docker compose exec db sh atlas/restore.sh backups/atlas-20261022-143529Z.dump atlas_restored
docker compose stop app worker scheduler
docker compose exec db sh atlas/use-restored.sh atlas_restored
docker compose start app worker scheduler
```

CI runs all of this on every pull request: it backs up a seeded database, restores it, compares every table, and serves the app from the restored copy.

### If something goes wrong

- **`docker compose` can't connect.** Docker Desktop isn't running. Start it, wait until it says the engine is running, and try again.
- **Port 8000 is already in use.** Add `ATLAS_PORT=8080` to `.env`, or any free port, and run `docker compose up -d` again.
- **`bind source path does not exist` mentioning `.env`.** Step 1 was skipped. Copy `.env.example` to `.env` and start again.
- **Docker Desktop closes on start with "The file cannot be accessed by the system".** It left socket files behind after a crash, and Windows won't delete them until it restarts. Restart Windows.

### Working on the code

To run the tests or change the front end, you also need PHP and Node on your machine:

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2 or newer | With the `pdo_pgsql` extension. In XAMPP's `php.ini`, remove the `;` in front of `extension=pdo_pgsql` and `extension=pgsql` |
| Composer | 2.x | [getcomposer.org/download](https://getcomposer.org/download/) |
| Node.js | 22 LTS, or 20.19 or newer | Vite 7 builds the front end and refuses older versions |

```bash
composer install          # never composer update, see below
npm install
```

Use `composer install`, never `composer update`. `composer.lock` pins every package, so every install gets the same versions. `update` rewrites the lock with whatever is newest on your machine, and everyone else's install changes with it.

The Docker image is built from the code in the folder, so after changing PHP or Blade files, `docker compose up -d --build` puts them into the running app. While working on the front end, `npm run dev` rebuilds the CSS and JavaScript on every save for an app served on your machine with `php artisan serve`, which uses the stack's database through `.env`.

### Running the tests

```bash
php artisan test
```

The tests use an in-memory SQLite database, so they need nothing running and never touch your data. To run them on PostgreSQL as well, the way the app really runs, start the database and point the suite at it:

```bash
docker compose up -d db
php artisan test -c phpunit.pgsql.xml
```

That uses a separate `atlas_test` database, which the stack creates the first time it starts. Never run the tests inside the app container: it caches its settings, which point at the real database.

### Signing in

Atlas NetCare has **no self-registration**: an administrator creates every account. Create the first administrator with:

```bash
docker compose exec app php artisan atlas:create-admin
```

The account's address is `admin@atlas.local`, from `ATLAS_ADMIN_EMAIL` in `.env`, unless you give another with `--email=you@example.com`. The command prints a random password **once, in your terminal, and nowhere else**. No password ships with the project, because a password in the repository would be public.

That password works for one sign-in. The account is flagged to change it, so the first sign-in lands on the profile screen, and every other page sends you back there until you choose your own.

If you lock yourself out, for example by deactivating or demoting the last administrator, run the same command with `--restore`. It makes that account an active administrator again and leaves its password alone. For a forgotten password, use "Forgot password" on the sign-in page.

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

The stack's `scheduler` service runs the scheduler for you, and restarts it if it stops. When running Atlas without Docker, start it in its own terminal with `php artisan schedule:work`. It runs seven jobs on a timer:

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

The charts don't need it to start with. `db:seed` writes two weeks of hourly history, with the last day as readings, so the Network Monitor opens on a full day. Three access points' latency and packet loss climb over the fortnight. Those three are what fill the risk model's High band.

> **Don't run `atlas:backfill-telemetry` after seeding demo data.** It writes steady readings into every hour it covers that has none and re-aggregates them, so a two-week backfill flattens those three trends. Use it only for access points with no history.

Telemetry is simulated by default, with `ATLAS_TELEMETRY_DRIVER=simulated`. Real access points need that `.env` value changed and `SnmpTelemetrySource` implemented. Nothing else in the system changes.
