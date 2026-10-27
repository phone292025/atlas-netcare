# Installation Guide

> **Last updated:** 2026-10-27
>
> Every command below was run on the development machine. Where a step can fail, what it looks like when it does is written next to it, because a guide that only describes success is not much use at 9am on the morning of a demonstration.

Atlas runs as one Docker Compose stack ([ADR-0012](decisions/ADR-0012-docker-compose-stack.md)): the web app, a queue worker, the scheduler and PostgreSQL with TimescaleDB ([ADR-0011](decisions/ADR-0011-postgresql-timescaledb.md)). Nothing but Docker is needed to run it. PHP and Node on the machine are only for running the tests and working on the front end.

---

## What you need first

| | Version used | Note |
|---|---|---|
| Docker Desktop | 4.71, with Engine 29 and Compose 5 | On Windows it runs on WSL 2, which the installer sets up |
| Git | any | Only to clone. An unzipped copy works without it |

To run the tests or work on the code, also:

| | Version used | Note |
|---|---|---|
| PHP | 8.2.12 | XAMPP's PHP is fine. It needs `pdo_pgsql` and `pdo_sqlite`: in `php.ini`, remove the `;` in front of `extension=pdo_pgsql` and `extension=pgsql` |
| Composer | 2.x | |
| Node.js | 24 | Vite 7 refuses anything older than 20.19 |

The image itself runs PHP 8.3 on FrankenPHP, whatever is installed on the machine.

---

## 1. Get the code

```bash
git clone https://github.com/phone292025/atlas-netcare.git
cd atlas-netcare
```

---

## 2. Create the configuration

```bash
cp .env.example .env
```

The stack mounts this file into its containers, and refuses to start without it. `compose.yaml` sets the database login, the log channel, the queue and `APP_URL` itself, and those win over the same names in `.env`. The app generates `APP_KEY` into `.env` on its first start.

Keys worth checking:

| Key | What it does |
|---|---|
| `ATLAS_PORT` | The port Atlas answers on. Not in the file, so 8000. Add it if 8000 is taken |
| `ATLAS_BIND` | The address the port listens on. Not in the file, so `127.0.0.1`, this machine only. See [Reaching it from a phone](#reaching-it-from-a-phone) |
| `ATLAS_ADMIN_EMAIL` | The first administrator's address |
| `ATLAS_DEMO_PASSWORD` | One shared password for the demonstration accounts |
| `APP_DEBUG` | `true` shows error details in the browser. Turn it off before anyone else can reach the app |

**There is no administrator password in `.env`.** `atlas:create-admin` creates the administrator and prints a random password once, in your terminal. A password in the repository would be public, so none ships.

---

## 3. Start the stack

```bash
docker compose up -d --build
```

The first build takes a few minutes: it downloads PHP, the Composer packages and Node, and builds the CSS and JavaScript inside the image. Then the app container creates the key, runs the migrations, caches its config and starts serving. The worker and the scheduler wait until the app is healthy.

```bash
docker compose ps
```

All four services should say `healthy` within about a minute. `docker compose logs -f app` shows what the app is doing meanwhile.

The database lives in the volume `db-data`, so it survives `stop`, `down` and rebuilds. `docker compose down -v` deletes it with every row in it.

---

## 4. Load the data and create the administrator

```bash
docker compose exec app php artisan db:seed
docker compose exec app php artisan atlas:create-admin
```

`db:seed` runs two seeders. `ReferenceSeeder` always runs and creates the six campus locations, the checklist and the shift patterns. `DemoDataSeeder` runs only while `ATLAS_DEMO_MODE=true` and adds 80 lab PCs, a core router with a switch in each room, 24 access points connected to those switches, a fortnight of telemetry, 25 inspections and 43 tickets, in about five seconds. It scores the assets as its last step, so the maintenance ranking opens with all four risk bands populated.

`atlas:create-admin` prints the administrator's address and password. Copy the password now: it is shown nowhere else.

---

## 5. Sign in

Open **http://localhost:8000**.

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@atlas.local` | Printed by `atlas:create-admin`, then your own |
| Network Engineer | `engineer@atlas.local` | `AtlasDemo!2026` |
| Student IT Assistant | `assistant.one@atlas.local` | `AtlasDemo!2026` |
| Student IT Assistant | `assistant.two@atlas.local` | `AtlasDemo!2026` |

The administrator is flagged `must_change_password`. The first sign-in lands on **Profile**, and every other screen sends you back there until you choose your own password. The printed one keeps working until then.

Re-running `db:seed` never creates or changes the administrator. If the last administrator is locked out or demoted, `docker compose exec app php artisan atlas:create-admin --restore --email=<address>` makes that account an active administrator again, keeping its password.

**These addresses are login identifiers, not mailboxes.** Nothing is sent to them, so signing in needs no mail account. Section 7 covers pointing them at a real mailbox if you want the password reset to deliver.

**The demo password is public**, because it is printed here and in the README. Before the app is reachable from anywhere but your own machine, set your own `ATLAS_DEMO_PASSWORD` and re-run `db:seed`, or remove the demo accounts with `atlas:purge-demo-data`.

Forgotten a demonstration password? Re-run the demo seeder. It resets them rather than failing:

```bash
docker compose exec app php artisan db:seed --class=DemoDataSeeder
```

### Reaching it from a phone

The stack listens on this machine only. To open it from a phone on the same Wi-Fi, add `ATLAS_BIND=0.0.0.0` to `.env` and run `docker compose up -d`. Then browse to `http://<your-machine-ip>:8000`, where `ipconfig` gives the IP. Windows asks once whether to let Docker through the firewall. It has to be allowed on public networks too, because most Wi-Fi networks are classified public.

Anyone on that Wi-Fi can then reach the sign-in page. Change the demo password first, and set `APP_DEBUG=false`.

Switching `ATLAS_BIND` can fail with "ports are not available" while Docker Desktop is still letting go of the old address. Run `docker compose up -d` again a few seconds later.

---

## 6. What keeps it live

The `scheduler` service runs `php artisan schedule:work`, and the `worker` service runs the queue. Both restart themselves after a crash. Without the scheduler, the dashboard shows whatever the last collection produced and quietly goes stale, which looks exactly like a broken dashboard.

| Command | When | What it does |
|---|---|---|
| `atlas:collect-telemetry` | every minute | One reading per access point, and marks silent ones offline |
| `atlas:dispatch-alarms` | every minute | Sends alarms that have come due |
| `atlas:aggregate-telemetry` | hourly | Rolls readings into hourly buckets |
| `atlas:recalculate-risk` | daily 02:00 | Rescores every asset, keeping history |
| `atlas:mark-missed-shifts` | every five minutes | Marks ended shifts nobody checked into as missed |
| `atlas:generate-shifts` | Sunday 18:00 | Next week's roster |
| `atlas:prune-telemetry` | weekly | Drops readings past retention |

`docker compose logs -f scheduler` shows each run.

---

## 7. Optional: password reset by email

FR-102 sends a reset link by email. Without mail it still works, and says plainly that nothing was sent. `.env.example` sends mail to the log.

1. The Google account needs **2-Step Verification** on. Then **Security → App passwords**, create one, and copy the sixteen characters.
2. In `.env`, set `MAIL_MAILER=smtp`, set `ATLAS_MAIL_ACCOUNT` to the Gmail name **without** `@gmail.com`, and paste the app password into `MAIL_PASSWORD`, **without the spaces Google displays**.
3. Restart the three app services so they read the new settings, and check them:

```bash
docker compose restart app worker scheduler
docker compose exec app php artisan atlas:mail-test
```

The app password is a key to that Google account. `.env` is ignored by git and must stay so.

---

## 8. Verify the installation

```bash
docker compose exec app php artisan atlas:check-schema
docker compose exec app php artisan migrate:status
```

Expect "Schema and data both look clean" and no pending migrations. `atlas:check-schema` is read only and safe against real data. It looks for rows that contradict themselves, such as a ticket closed before it was resolved or a risk score outside 0 to 100. **Run it after any migration.**

The test suite runs on the machine, never in the app container. The container caches its config, which points at the real database, and a test run against cached config would wipe it. The image carries no tests for that reason.

```bash
composer install
npm install
php artisan test
```

Expect **1,064 passing, 1 skipped**, on an in-memory SQLite database. The skip is deliberate: `SchemaIntegrityTest` checks a MariaDB trap, and Atlas no longer runs on MariaDB. To run the suite on PostgreSQL with TimescaleDB as well, which CI does on every pull request:

```bash
php artisan test -c phpunit.pgsql.xml
```

That is **1,064 passing, 1 skipped** too, in about three minutes. It uses a separate `atlas_test` database, which the stack creates on its first start. Each run drops every table in the database it uses, so pointing it at `atlas` would erase real work.

The browser tests run five core flows in Chromium against a stack of their own, on ports 8100 and 5433:

```bash
npx playwright install chromium
npm run test:browser
```

Expect **9 passed**, in about a minute once the image is cached. The run deletes its stack at the end, so it never touches yours. [07-testing.md](07-testing.md) says what each one covers.

---

## Working on the code

The image is built from the code in the folder, so after changing PHP or Blade, `docker compose up -d --build` puts the change into the running app.

For front-end work, serve the app from the machine instead, against the stack's database. `.env.example` already points at it, on `127.0.0.1:5432`:

```bash
docker compose up -d db
php artisan serve
npm run dev
```

`npm run dev` rebuilds the CSS and JavaScript on every save. **Before `npm run build`, run `php artisan view:clear`.** Compiled Blade views count as Tailwind source, so a stale view cache inflates the CSS and keeps classes alive for deleted files.

---

## Turning it into a clean deployable system

```bash
docker compose exec app php artisan atlas:purge-demo-data --dry-run
docker compose exec app php artisan atlas:purge-demo-data
```

Removes every demonstration row and leaves the reference data, the administrator and anything real. It **refuses** if a genuine record points at a demonstration asset, rather than leaving it dangling. Pass `--allow-orphans` only if that is genuinely what you want. Then set `ATLAS_DEMO_MODE=false` so a future seed doesn't put it back.

Backups and restores are in the README, under "Backups".

---

## When something goes wrong

**`docker compose` can't connect to the Docker API** — Docker Desktop isn't running. Start it and wait until it says the engine is running.

**Docker Desktop closes on start with "The file cannot be accessed by the system"** — it left socket files behind after an unclean exit, and Windows won't delete them until it restarts. Restart Windows. Never choose "Reset to factory defaults": it deletes the database volume.

**`bind source path does not exist` mentioning `.env`** — step 2 was skipped. Copy `.env.example` to `.env`.

**`port is already allocated`** — something else uses port 8000, or 5432 for the database. Set `ATLAS_PORT` in `.env` for the app. For the database, set `ATLAS_DB_PORT`, and change `DB_PORT` to match if you run PHP on the machine.

**A setting in `.env` seems ignored** — the app caches its config when it starts, and the worker and scheduler read it once. `docker compose restart app worker scheduler` makes all three read it again.

**There is no administrator** — seeding doesn't create one. Run `atlas:create-admin`.

**Every asset scores Low** — the risk model has not run, or the assets seeded without ages. `docker compose exec app php artisan atlas:recalculate-risk`.

**Charts are blank** — nothing was collected in the last day, because the scheduler wasn't running. Check `docker compose ps`. Its first run afterwards fills the gap with simulated readings, up to the last 24 hours, so the charts come back full. With a real driver it does not, because a gap in real telemetry is a fact.

**`SQLSTATE[08006]` or "Connection refused" from `php artisan` on the machine** — the stack's database isn't running. `docker compose up -d db`.

## If ticket numbers look like TKT-2026-96851

A database seeded before 2026-09-04 got random ticket references from the old factory, and every real ticket raised since continued from the highest one. The numbers are unique and nothing is broken. They are just not the readable sequence the format exists for.

```bash
docker compose exec app php artisan atlas:renumber-tickets --dry-run
docker compose exec app php artisan atlas:renumber-tickets
```

Sequential per year in the order tickets were opened. Any comment, alarm or audit row that quoted an old number is rewritten to the new one, and each renumbered ticket gets an audit row carrying the number it had. Safe to re-run: a sequential database reports nothing to do.
