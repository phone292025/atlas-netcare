# ADR-0012: Run Atlas as one Docker Compose stack

**Status:** Accepted
**Date:** 2026-10-22
**Deciders:** Phone Myat Ko

## Context

Atlas 1.0 runs on XAMPP, with each part started by hand: MariaDB, `php artisan serve`, and `php artisan schedule:work`. In practice:

- The scheduler stopped several times and nobody noticed until the charts were empty. That is why `TelemetryBackfill::fillGap()` exists.
- MariaDB was left running as a half-stopped process after a shutdown, and once failed to start on the first try.
- People cloning the repository hit the same setup traps:
  - `.env.example` points every install at the same database name. A second install on a machine that already has one connects to the old database, and with it the old admin account and password.
  - The front-end build isn't in git, so a clone without `npm run build` shows old or missing styles.
- 2.0 adds more processes: a queue worker for background jobs, and in Phase 3 a WebSocket server. Starting five things by hand, in the right order, is not a reasonable way to run it.

[ADR-0011](ADR-0011-postgresql-timescaledb.md) also needs PostgreSQL with TimescaleDB, which XAMPP doesn't provide.

## Decision

Atlas runs as **one Docker Compose stack**, started with `docker compose up`.

| Service | Runs | Notes |
|---|---|---|
| `app` | The web app, served by FrankenPHP | Serves HTTP. Caddy inside it can provide HTTPS when the stack moves to a server |
| `worker` | `php artisan queue:work` | Background jobs. Same image as `app` |
| `scheduler` | `php artisan schedule:work` | Scheduled commands. Same image as `app` |
| `db` | PostgreSQL 16 with TimescaleDB | Data in a named volume, so it survives the stack being rebuilt |

- **One image** for `app`, `worker` and `scheduler`, built by one multi-stage `Dockerfile`. It installs Composer dependencies from the lock file and runs the front-end build, so the image always carries the CSS and JavaScript that match its code.
- **PHP 8.3** in the image. Laravel 12 supports PHP 8.2 to 8.4. The project stays on Laravel 12 for now (ADR-0008); moving to Laravel 13 becomes possible later, because the PHP version is no longer set by XAMPP.
- **Every service restarts itself** after a crash (`restart: unless-stopped`), and `app` and `db` have health checks.
- **Migrations run on start.** Demo data is seeded only when asked for, with a one-off command.
- **The administrator is created by a command,** not from a password in `.env.example`. The published default password goes away.
- **The WebSocket server joins the stack in Phase 3,** when something uses it.
- **XAMPP is no longer how Atlas runs.** PHP on the host is still enough to run the test suite on SQLite.

## Consequences

**Easier**
- A fresh clone runs with one command, with nothing installed but Docker.
- A crashed scheduler or worker restarts on its own, so a gap in readings now means an outage, not a forgotten terminal.
- The same stack moves to a server unchanged.
- The database, PHP and the front-end build are the same versions everywhere.

**Harder**
- Docker Desktop has to be installed and running. On Windows that means WSL 2 and a few gigabytes of disk.
- The first build is slow: Composer, npm and PHP extensions. Later builds reuse cached layers.
- Debugging means reading container logs (`docker compose logs`) instead of a terminal window.

## Alternatives considered

**Laravel Sail.** It is Laravel's own Docker setup, but it is built for development: it serves through `artisan serve` and isn't meant for running in production. Atlas needs one setup for both.

**nginx and PHP-FPM as two containers.** This is the usual production pair, and it works. FrankenPHP does the same job in one container, with HTTPS built in, which leaves one less moving part.

**Keep XAMPP and add start scripts.** This fixes the order of starting things, but not crashes, versions or the missing PostgreSQL.
