<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Nothing here runs unless the scheduler itself is running. On a development
| machine that means one long-lived process:
|
|     php artisan schedule:work
|
| Without it the dashboard shows whatever the last manual `atlas:collect-
| telemetry` produced and quietly goes stale — which looks exactly like a
| broken dashboard.
|
| Every command below enters at the SERVICE layer and skips controllers
| entirely. That is the point of putting the rules in services: a scheduled
| command and a browser request share one code path.
|
| @see docs/modules/M5-telemetry-and-dashboard.md
*/

// One reading per access point. withoutOverlapping so a slow round cannot
// stack up behind itself and write two readings for the same minute.
Schedule::command('atlas:collect-telemetry')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Rebuilds the last few hours rather than only the last one — a late
 * collection run or a backfill landing after the previous aggregation would
 * otherwise leave a permanently wrong bucket nothing revisits. Safe to repeat:
 * it upserts against a unique index.
 */
Schedule::command('atlas:aggregate-telemetry --hours=3')
    ->hourly()
    ->withoutOverlapping();

// Retention is 90 days of raw readings and 12 months of aggregates (Q10).
// Weekly and out of hours, because it deletes in chunks across a large table.
Schedule::command('atlas:prune-telemetry')
    ->weeklyOn(0, '03:15');
