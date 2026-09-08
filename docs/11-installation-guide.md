# Installation Guide

> **Last updated:** 2026-09-04 · **For the report's Appendix (item 21).**
>
> Every command below was run on the development machine. Where a step can fail, what it looks like when it does is written next to it, because a guide that only describes success is not much use at 9am on submission day.

---

## What you need first

| | Version used | Note |
|---|---|---|
| PHP | 8.2.12 | XAMPP ships this. `php -v` to check. |
| Composer | 2.x | Dependency manager for PHP. |
| Node.js | 20+ | Only for building the CSS and JavaScript. |
| MariaDB | 10.4.32 | XAMPP ships this. MySQL 8 also works. |

**XAMPP is the assumed environment.** Start **Apache** and **MySQL** from the XAMPP control panel before anything below. If MySQL will not start, something else is already using port 3306 — usually a previously installed MySQL service.

---

## 1. Get the code and its dependencies

```bash
git clone https://github.com/phone292025/atlas-netcare.git
cd atlas-netcare
composer install
npm install
```

`composer install`, not `composer update`. The lock file pins Laravel 12 deliberately ([ADR-0008](decisions/ADR-0008-laravel-12-pinned.md)); updating would move the framework underneath the project.

---

## 2. Create the configuration

```bash
cp .env.example .env
php artisan key:generate
```

Then open `.env` and set these. **The application will not seed without the first two.**

| Key | What to put |
|---|---|
| `DB_DATABASE` | `atlas_netcare` |
| `DB_USERNAME` | `root` — XAMPP's default |
| `DB_PASSWORD` | Empty on a default XAMPP install |
| `ATLAS_ADMIN_EMAIL` | The first administrator's address |
| `ATLAS_ADMIN_PASSWORD` | **Choose your own.** There is deliberately no default |
| `ATLAS_DEMO_PASSWORD` | One shared password for the demonstration accounts |

`DB_CONNECTION` is already `mariadb`, not `mysql`. XAMPP ships MariaDB and Laravel 12 has a dedicated driver for it; the distinction matters because MariaDB implements `JSON` as `LONGTEXT` with a check constraint rather than as a native type.

**There is no default administrator password.** If `ATLAS_ADMIN_PASSWORD` is unset the seeder refuses and says so, rather than creating the highest-privilege account with a credential printed in a public repository.

---

## 3. Create the database

In phpMyAdmin, or on the command line:

```bash
mysql -u root -e "CREATE DATABASE atlas_netcare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

On Windows, `mysql` is usually not on the PATH. The full path is `C:\xampp\mysql\bin\mysql.exe`.

---

## 4. Build the schema and the data

```bash
php artisan migrate
php artisan db:seed
```

`db:seed` runs two seeders. `ReferenceSeeder` always runs and creates the first administrator and the six campus locations. `DemoDataSeeder` runs only while `ATLAS_DEMO_MODE=true` and adds 80 lab PCs, 24 access points, a fortnight of telemetry, 25 inspections and 43 tickets — about three seconds.

Then score the assets, or the maintenance ranking will be empty:

```bash
php artisan atlas:recalculate-risk
```

You should see all four risk bands populated. If everything lands in Low, the assets seeded without their ages — reseed rather than adjusting the weights.

---

## 5. Build the front end and start it

```bash
npm run build
php artisan serve
```

Open **http://127.0.0.1:8000**.

**Always run `php artisan view:clear` before `npm run build`.** Compiled Blade views count as Tailwind source, so a stale view cache inflates the CSS by about 12 KB and keeps classes alive for deleted files.

### Reaching it from a phone

`php artisan serve` binds to localhost only. For another device on the same Wi-Fi:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Then browse to `http://<your-machine-ip>:8000`. `ipconfig` gives the IP. Windows Firewall will ask once whether to allow PHP; it has to be allowed for the private **and** public profile, because most Wi-Fi networks are classified public.

---

## 6. Sign in

The demonstration accounts come straight from `.env.example`, so a fresh clone can open every role's screens. The administrator does not: its password is blank in that file and you set it in step 2.

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@atlas.local` | whatever you set as `ATLAS_ADMIN_PASSWORD` |
| Network Engineer | `engineer@atlas.local` | `AtlasDemo!2026` |
| Student IT Assistant | `assistant.one@atlas.local` | `AtlasDemo!2026` |
| Student IT Assistant | `assistant.two@atlas.local` | `AtlasDemo!2026` |

Re-running `db:seed` later will **not** reset the administrator's password back to what `.env` says. It used to, on every run.

**These addresses are login identifiers, not mailboxes.** Nothing is ever sent to them, so signing in needs no mail account. Section 8 covers pointing them at a real mailbox if you want the password reset to deliver.

The administrator is flagged `must_change_password`, so the first sign-in lands on **Profile** and every other screen redirects back there until a new password is set. Keeping `.env` up to date is still worth doing, because that is where you will look for it in a month — but re-seeding no longer overwrites it, so forgetting is no longer a way to lock yourself out.

Demonstration accounts are not flagged, so they sign straight in.

Forgotten a demonstration password? Re-run the seeder — it resets them rather than failing:

```bash
php artisan db:seed --class=DemoDataSeeder
```

---

## 7. Keep it live

Nothing updates on its own unless the scheduler is running. On a development machine that is one long-lived process:

```bash
php artisan schedule:work
```

Without it, the dashboard shows whatever the last manual collection produced and quietly goes stale — which looks exactly like a broken dashboard.

| Command | When | What it does |
|---|---|---|
| `atlas:collect-telemetry` | every minute | One reading per access point, and marks silent ones offline |
| `atlas:dispatch-alarms` | every minute | Sends alarms that have come due |
| `atlas:aggregate-telemetry` | hourly | Rolls readings into hourly buckets |
| `atlas:recalculate-risk` | daily 02:00 | Rescores every asset, keeping history |
| `atlas:generate-shifts` | Sunday 18:00 | Next week's roster, and marks missed shifts |
| `atlas:prune-telemetry` | weekly | Drops readings past retention |

On Windows, a single Task Scheduler entry running `php artisan schedule:run` every minute drives all six.

---

## 8. Optional: password reset by email

FR-102 sends a reset link by email. It works without this, but says plainly that nothing was sent.

1. The Google account needs **2-Step Verification** on. Then **Security → App passwords**, create one, and copy the sixteen characters.
2. In `.env`, set `ATLAS_MAIL_ACCOUNT` to the Gmail name **without** `@gmail.com`, and paste the app password into `MAIL_PASSWORD` — **without the spaces Google displays**.
3. Check it:

```bash
php artisan atlas:mail-test
```

The app password is a key to that Google account. `.env` is gitignored and must stay so.

---

## 9. Verify the installation

```bash
php artisan test
php artisan atlas:check-schema
php artisan migrate:status
```

Expect **830 passing, 1 skipped**; "Schema and data both look clean"; and no pending migrations.

The skip is deliberate: `SchemaIntegrityTest` only means anything on MariaDB, and `php artisan test` runs on SQLite. To run everything against a real MariaDB, create the test database once and use the other config:

```bash
mysql -u root -e "CREATE DATABASE atlas_netcare_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan test -c phpunit.mariadb.xml
```

That is **831 passing, none skipped**, and it is what CI runs on every push. `atlas_netcare_test` is a separate database on purpose: the suite truncates what it touches, and pointing it at `atlas_netcare` would erase real work.

`atlas:check-schema` is read-only and safe against real data. It catches two things the test suite cannot, because the suite runs on SQLite and the system runs on MariaDB: columns MariaDB silently rewrites on every update, and rows whose own timestamps contradict each other. **Run it after any migration and before submission.**

---

## Turning it into a clean deployable system

```bash
php artisan atlas:purge-demo-data --dry-run
php artisan atlas:purge-demo-data
```

Removes every demonstration row and leaves the reference data, the administrator and anything real. It **refuses** if a genuine record points at a demonstration asset, rather than leaving it dangling — pass `--allow-orphans` only if that is genuinely what you want. Then set `ATLAS_DEMO_MODE=false` so a future seed does not put it back.

---

## When something goes wrong

**`SQLSTATE[HY000] [2002]`** — MySQL is not running. Start it in XAMPP.

**`Please provide a valid cache path`** — the storage directories are missing after a fresh clone. `php artisan optimize:clear`, and make sure `storage/framework/{cache,sessions,views}` exist.

**The administrator was not created** — `ATLAS_ADMIN_PASSWORD` is unset. That refusal is deliberate.

**Every asset scores Low** — the risk model has not run, or the assets seeded without ages. `php artisan atlas:recalculate-risk`.

**Charts are blank** — no telemetry. `php artisan atlas:backfill-telemetry --days=14`, then run the aggregator.

**Styles look broken** — the front end has not been built. `php artisan view:clear && npm run build`.

## If ticket numbers look like TKT-2026-96851

A database seeded before 2026-09-04 got random ticket references from the old factory, and every real ticket raised since continued from the highest one. The numbers are unique and nothing is broken; they are just not the readable sequence the format exists for.

```bash
php artisan atlas:renumber-tickets --dry-run
php artisan atlas:renumber-tickets
```

Sequential per year in the order tickets were opened. Any comment, alarm or audit row that quoted an old number is rewritten to the new one, and each renumbered ticket gets an audit row carrying the number it had. Safe to re-run: a sequential database reports nothing to do.
