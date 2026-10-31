<?php

use App\Support\CampusClock;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled work. Nothing here runs unless the scheduler does; on a
| development machine that is one long-lived process:
|
|     php artisan schedule:work
|
| Without it the dashboards quietly go stale. Each command calls a service,
| so a scheduled run and a browser request share one code path. Times of day
| are on the campus clock.
|
| @see docs/modules/M5-telemetry-and-dashboard.md
*/

// Every withoutOverlapping() below names how long its lock lasts. The default
// is 24 hours, the lock lives in the database cache, and a scheduler killed
// mid-run left it behind: no readings or alarms until the next day.

// One reading per access point. withoutOverlapping so a slow round cannot
// stack up behind itself and write two readings for the same minute.
Schedule::command('atlas:collect-telemetry')
    ->everyMinute()
    ->withoutOverlapping(5);

// Hourly summaries, compression and retention are TimescaleDB's own jobs,
// set from config('atlas.retention') when the app starts. Nothing to schedule
// here. See App\Services\Telemetry\TelemetryStorage.

// M6: alarms are written ahead with a `trigger_at`; this delivers them.
// Every minute, because a late reminder teaches people to ignore them.
Schedule::command('atlas:dispatch-alarms')
    ->everyMinute()
    ->withoutOverlapping(5);

// Next week's roster on Sunday evening. Idempotent.
Schedule::command('atlas:generate-shifts')
    ->weeklyOn(0, '18:00')
    ->timezone(CampusClock::timezone());

// Shifts that ended with nobody checked in, marked missed within minutes
// rather than on Sunday, so the roster never offers to reassign the past.
Schedule::command('atlas:mark-missed-shifts')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

/*
 * M7: risk scores (FR-505), out of hours so the ranking does not reorder under
 * somebody reading it, and at 02:00 so the previous day's hours have been
 * summarised first.
 */
Schedule::command('atlas:recalculate-risk --show=0')
    ->dailyAt('02:00')
    ->timezone(CampusClock::timezone())
    ->withoutOverlapping(60);
