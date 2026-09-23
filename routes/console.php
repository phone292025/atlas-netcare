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

// One reading per access point. withoutOverlapping so a slow round cannot
// stack up behind itself and write two readings for the same minute.
Schedule::command('atlas:collect-telemetry')
    ->everyMinute()
    ->withoutOverlapping();

// The last few hours, not just the last one, so a late round is still
// counted. Safe to repeat: it upserts against a unique index.
Schedule::command('atlas:aggregate-telemetry --hours=3')
    ->hourly()
    ->withoutOverlapping();

// Retention is 90 days of raw readings and 12 months of aggregates (Q10).
// Weekly and out of hours, because it deletes in chunks across a large table.
Schedule::command('atlas:prune-telemetry')
    ->weeklyOn(0, '03:15')
    ->timezone(CampusClock::timezone());

// M6: alarms are written ahead with a `trigger_at`; this delivers them.
// Every minute, because a late reminder teaches people to ignore them.
Schedule::command('atlas:dispatch-alarms')
    ->everyMinute()
    ->withoutOverlapping();

// Next week's roster on Sunday evening, and past shifts nobody attended
// marked missed. Idempotent.
Schedule::command('atlas:generate-shifts')
    ->weeklyOn(0, '18:00')
    ->timezone(CampusClock::timezone());

/*
 * M7: risk scores (FR-505), out of hours so the ranking does not reorder under
 * somebody reading it, and at 02:00 so the previous day's telemetry has been
 * aggregated first.
 */
Schedule::command('atlas:recalculate-risk --show=0')
    ->dailyAt('02:00')
    ->timezone(CampusClock::timezone())
    ->withoutOverlapping();
