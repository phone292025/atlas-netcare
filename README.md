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

Everything listed above is built, on Laravel 12 with the Laravel Breeze starter kit. This is release 2.0: real telemetry from probe agents, and incidents that alert whoever is on call. Live-pushed screens with a network map, and offline phone rounds with phone push, are the next releases and are not in this one.

The suite has 1,318 tests, and CI runs it on SQLite and on PostgreSQL with TimescaleDB. One test checks a MariaDB trap and skips itself on both. Eleven need PostgreSQL and skip on SQLite: three check TimescaleDB, five hold a lock from a second connection, and three check the database password. One checks the SQLite path and skips on PostgreSQL. A copy without the `docs` folder also skips the seven tests that check it. CI also runs 41 browser tests against the Docker stack, five core flows and accessibility checks on every screen in Chromium and in Firefox; checks that scheduled backups are made, restored, copied and watched; and the Go agent's tests on Linux and Windows.

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

Then open `.env` and set `DB_PASSWORD` to a long random password of your own, 16 characters or more. The stack refuses to start without one. A password manager can make one, or Git Bash: `openssl rand -hex 16`.

**2. Start the stack.**

```bash
docker compose up -d --build
```

The first build takes a few minutes, because it downloads PHP, the PHP packages and the front-end tools. After that the app creates its key and builds the database on its own. It's ready when this shows `app` as `healthy`:

```bash
docker compose ps
```

**3. Load the demonstration data.** It takes a few seconds and includes two weeks of telemetry, the risk scores, and a sign-in for each role, the administrator included:

```bash
docker compose exec app php artisan db:seed
```

**4. Open [http://localhost:8000](http://localhost:8000) and sign in** as `admin@atlas.local` with the password `AtlasDemo!2026`. The scheduler is already running inside the stack, so the Network Monitor stays live. [Signing in](#signing-in) lists the other accounts and shows how to create your own administrator.

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

The stack's `backup` service backs the database up once a day into `backups/`, and deletes dumps older than 14 days. `.env.example` turns it on with `COMPOSE_PROFILES=backup`; `ATLAS_BACKUP_EVERY_HOURS` and `ATLAS_BACKUP_KEEP_DAYS` change the timing. Each new backup is restored into a scratch database, compared row by row with the file, and the scratch database dropped, so a backup that would not restore counts as a failed one. That takes about as long as the backup itself; `ATLAS_BACKUP_VERIFY=false` turns it off.

**Set `ATLAS_BACKUP_COPY_DIR`** in `.env` to a folder on another disk, or a network share mounted on this machine, and every backup is copied there as well. A backup on the same disk as the database is lost with it, so with `APP_ENV=production` the app refuses to start without one, unless `ATLAS_BACKUPS_ELSEWHERE=true` says the server is backed up another way.

The service writes how each attempt went into `backups/status`. `docker compose ps backup` shows it unhealthy once the newest good backup is more than two hours overdue, and every hour the scheduler's `atlas:check-backups` reads the same file: the administrators get an alarm when a backup is late, the last attempt failed, or the copy is behind.

To take one now:

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
- **`required variable DB_PASSWORD is missing a value`.** Set `DB_PASSWORD` in `.env`, as step 1 says.
- **`password authentication failed for user "atlas"` after updating.** Older copies of `compose.yaml` set the password to `atlas` itself, and the database keeps the password it was created with. Put `DB_PASSWORD=atlas` in `.env`, or change it in the database first: `docker compose exec db psql -U atlas -c "ALTER USER atlas PASSWORD '<new password>'"`, then put the new one in `.env`.
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

That uses a separate `atlas_test` database, which the stack creates the first time it starts. It signs in with the password `atlas`; if your stack has its own, pass it in: `DB_PASSWORD=<yours> php artisan test -c phpunit.pgsql.xml`. Never run the tests inside the app container: it caches its settings, which point at the real database.

### Running the browser tests

Five flows run against the Docker stack, in Chromium and again in Firefox: signing in as each role, raising and resolving a ticket, inspecting a machine, the Network Monitor, and printing the report, with accessibility checks on every screen. With Docker Desktop running, install the browsers once, then run them:

```bash
npx playwright install chromium firefox
npm run test:browser
```

A run builds an image of its own from your code and starts a second stack for itself, on ports 8100 and 5433, with settings copied from `.env.example`. It seeds demo data, runs the tests and deletes that stack, so it never touches your data or sends real mail. Once the image is cached, a run takes about ten minutes. A failed test leaves a screenshot and a trace in `test-results/browser`, and `npx playwright show-trace <path to trace.zip>` replays it step by step.

### Signing in

Atlas NetCare has **no self-registration**: an administrator creates every account.

### Default accounts

While `ATLAS_DEMO_MODE=true`, `db:seed` creates one sign-in per role. They all share one password:

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@atlas.local` | `AtlasDemo!2026` |
| Network Engineer | `engineer@atlas.local` | `AtlasDemo!2026` |
| Student IT Assistant | `assistant.one@atlas.local` | `AtlasDemo!2026` |
| Student IT Assistant | `assistant.two@atlas.local` | `AtlasDemo!2026` |

Those are the values a fresh clone gets from `.env.example` (`ATLAS_ADMIN_EMAIL`, `ATLAS_DEMO_PASSWORD` and the `ATLAS_DEMO_*_EMAIL` lines). They are **login identifiers only**. Nothing is sent to them, so you can sign in without owning any mailbox. Two assistants on purpose: one can't show that an assistant sees only *their own* inspections and tickets.

> **That password is public: it is printed right here.** Before the app is reachable from anywhere but your own machine, create your own administrator (below) and remove the default accounts with `docker compose exec app php artisan atlas:purge-demo-data`. Or set your own `ATLAS_DEMO_PASSWORD` in `.env`, run `docker compose restart app worker scheduler`, and seed again.

**Forgotten or changed one?** Seed again. It resets the default accounts' passwords rather than failing, and never touches a real account that uses one of the same addresses:

```bash
docker compose exec app php artisan db:seed --class=DemoDataSeeder
```

> **Changing an address in `.env` on a database that is already seeded gives you a second account, not a renamed one.** The seeder matches on email, so the old row keeps its tickets and shifts and you sign in to an empty copy. Rename the existing row instead.

The default accounts are tagged `is_demo`. With `ATLAS_DEMO_MODE=false` they are never created, and a production install (`APP_ENV=production`) gets none of them, because their password is printed here. `atlas:purge-demo-data` removes them, the default administrator included, so create your own administrator first. It takes `--dry-run` to show what would go, and refuses if a real record points at a demonstration asset rather than leaving it dangling.

### Creating an administrator account

Create your own administrator from the terminal. On a clean install (`ATLAS_DEMO_MODE=false`) this is how the first account comes to exist:

```bash
docker compose exec app php artisan atlas:create-admin --email=you@example.com --name="Your Name"
```

1. The command prints a random password **once, in your terminal, and nowhere else**. Copy it before you close the terminal.
2. Open [http://localhost:8000](http://localhost:8000) and sign in with your address and that password.
3. Atlas opens your profile and asks you to choose your own password. Every other page sends you back there until you do. The printed password keeps working until then.

Without `--email`, the command uses `ATLAS_ADMIN_EMAIL` from `.env`, which is `admin@atlas.local`. On a demo install that address is already the default administrator, and the command refuses it, so give your own.

**More accounts, more administrators.** Once you're signed in as an administrator, open **Users** and choose **Add user**. Pick the role, **Administrator** included, and a starting password. The new user has to change it at their first sign-in.

**Locked out?** If the last administrator was deactivated or demoted, make that account an active administrator again. Its password stays as it was:

```bash
docker compose exec app php artisan atlas:create-admin --restore --email=you@example.com
```

For a forgotten password, use "Forgot password" on the sign-in page.

### Email

Atlas sends one kind of email: the password reset link. Out of the box nothing is sent. `MAIL_MAILER=log` writes each message into the app's log instead. The reset screen shows the link as well, but only when you open Atlas at `localhost` on the machine running it: not with `ATLAS_BIND=0.0.0.0`, and not through a tunnel, where anybody could ask for an account's link. Otherwise, find it in the app's log, which under Docker is the container's output:

```bash
docker compose logs app | grep reset-password
```

To send real mail, put your mail provider's SMTP settings in `.env`:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=you@example.com
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=you@example.com
```

Then restart the app so it reads them, and send a test message:

```bash
docker compose restart app worker scheduler
docker compose exec app php artisan atlas:mail-test
```

That sends one real message and, if the server refuses it, says which of the usual causes it probably is. The test suite fakes the mailer, so this command is the only check that mail works. A reset link goes to the account's own address, so an account needs a real address to receive one.

**Keep the mail password in `.env` only.** `.env` is ignored by git; `.env.example` is not.

### Probe agents

A probe agent is a small program on a machine inside the network. It measures the devices it watches and sends their readings to Atlas, which turns them into statuses, tickets and alerts the same way as the simulator's.

1. Sign in as the administrator, open **Agents** and add one. Atlas shows its token once: copy it into the agent's config.
2. Open each device the agent should watch, under **Access points** or **Network devices**, and set **Watched by**.
3. The agent sends batches to `POST /api/v1/readings` with the header `Authorization: Bearer <token>`.

A batch looks like this:

```json
{
  "sent_at": "2026-10-28T03:00:05Z",
  "agent_version": "0.1.0",
  "readings": [
    {"sequence": 1201, "device": "SW-L6-IT-01", "measured_at": "2026-10-28T02:59:30Z",
     "reachable": true, "latency_ms": 2, "packet_loss_pct": 0}
  ]
}
```

`sequence` counts up for each reading the agent takes, and a batch sent twice is stored once. `latency_ms`, `packet_loss_pct`, `client_count`, `channel_util_pct` and `throughput_mbps` are optional. An empty `readings` list is a heartbeat. The answer lists every sequence as `stored`, `duplicate` or `rejected` with a reason, and the agent can drop all of them. Atlas refuses a reading for a device the agent doesn't watch, one dated more than 5 minutes ahead, and one more than 7 days old.

Atlas also records each agent's version, address and clock, and flags a clock more than 30 seconds out. An agent that sends nothing for 3 minutes is **silent**: its devices show as Unknown instead of their last status, and the engineers and administrators get one alert. Its next reading for a device puts that device back. Until an agent first connects, its devices show as Unknown too, without an alert.

The token travels in every request, so **Atlas refuses an agent that connects over plain HTTP**, with 403. That includes an agent on the same machine as the Docker stack, because every request reaches the stack through Docker's gateway, a reverse proxy's on the host included; while testing, `ATLAS_AGENTS_ALLOW_PLAIN_HTTP=true` allows it. Give the stack a name and HTTPS first, in `.env`:

```dotenv
ATLAS_BIND=0.0.0.0
ATLAS_SERVER_NAME=atlas.lab.internal
ATLAS_URL=https://atlas.lab.internal
ATLAS_PORT=80
ATLAS_HTTPS_PORT=443
```

Then `docker compose up -d`. Caddy, the web server inside the image, serves HTTPS and redirects port 80 to it. For a name ending in `.internal`, `.local` or `.home.arpa`, or an IP address, the certificate comes from Caddy's own authority, which each agent machine must trust: `docker compose cp app:/data/caddy/pki/authorities/local/root.crt atlas-root.crt` copies its root out. A public name that resolves to this machine, with ports 80 and 443 open, gets a Let's Encrypt certificate instead. The name must reach this machine from the agent, through DNS or the agent machine's hosts file.

The agent itself is in `agent/`, written in Go: one file per platform, for Windows, Linux and Raspberry Pi. It pings, fetches a URL and asks SNMP (v3, or v2c for older devices), all at once, and keeps readings in a local SQLite file while Atlas is unreachable. `agent/README.md` shows how to set it up.

### Keeping the monitor live

The stack's `scheduler` service runs the scheduler for you, and restarts it if it stops. When running Atlas without Docker, start it in its own terminal with `php artisan schedule:work`. It runs seven jobs on a timer:

| Job | When | What it does |
|---|---|---|
| `atlas:collect-telemetry` | every minute | Takes the simulator's readings. A device that has been silent for 5 minutes while its agent reports goes offline. A silent agent's devices turn Unknown |
| `atlas:watch-incidents` | every minute | Opens and resolves incidents from the devices' and agents' current state, and tells the next people on call about any incident nobody has acknowledged |
| `atlas:dispatch-alarms` | every minute | Sends the alarms that have come due |
| `atlas:mark-missed-shifts` | every 5 minutes | Marks a shift missed when it has ended and nobody checked in |
| `atlas:check-queue` | every 5 minutes | Alerts the administrators, in the app, if the worker has stopped or jobs keep failing: incidents' alerts depend on it |
| `atlas:recalculate-risk` | daily at 02:00 | Rescores every asset and keeps the old scores |
| `atlas:generate-shifts` | Sundays at 18:00 | Builds next week's roster |

The times are campus time.

### Incidents and on-call

A fault becomes an **incident**: a device that stops answering, degrades or keeps changing status, or an agent that stops reporting. One per fault: when a switch and the access points behind it fail together, it is one incident on the switch, with one ticket. An incident resolves itself when the device recovers. A device that fails again while its ticket is still open reuses that ticket.

Who is told comes from the roster. First the assistants checked in to a shift, those in the device's room before anyone else; if nobody acknowledges within 10 minutes, the engineers; then the administrators. Acknowledging, on the incident's page under **Incidents**, stops it. A silent agent goes straight to engineers and administrators.

Alerts arrive in the app, by email when mail is set up, and on Telegram when `.env` has `ATLAS_TELEGRAM_BOT_TOKEN` and the person has put a Telegram chat id on their profile. Each alert reaches each person on each channel once, however often it is retried.

The `worker` service sends them: the stack runs it, and without Docker it is `php artisan queue:work` in a terminal of its own.

Hourly summaries, compression and retention aren't on this list. The database runs them itself, as TimescaleDB jobs, set from `config/atlas.php` every time the app starts (`php artisan atlas:telemetry-storage`). Raw readings are compressed after 7 days and dropped after 90, and hourly summaries are kept for 12 months.

Without the scheduler nothing looks broken at first. Five minutes after the last reading, every access point on the Network Monitor reads Unknown, and no new alarms reach the bell.

Turning it back on repairs the charts by itself. Its first round sees the silence and fills it with simulated readings, up to the last 24 hours, so the Network Monitor comes back to a full day instead of a single point.

The charts don't need it to start with. `db:seed` writes two weeks of hourly history, with the last day as readings, so the Network Monitor opens on a full day. Three access points' latency and packet loss climb over the fortnight. Those three are what fill the risk model's High band.

`atlas:backfill-telemetry` invents history for devices that have none, such as the seeded switches and router. Moments a device already has a reading for are left alone, so running it after seeding keeps the three trends. Keep its `--minutes` at the default 5 there: a smaller step adds steady readings between the seeded ones and flattens the trends.

The demo's devices are watched by the simulator, a virtual agent inside Atlas. To measure a real device, set its **Watched by** to a probe agent, as in Probe agents above. The simulation controls and the backfill leave devices a real agent watches alone.
