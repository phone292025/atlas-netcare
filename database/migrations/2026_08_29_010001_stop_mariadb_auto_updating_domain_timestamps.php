<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop MariaDB silently rewriting three columns that are ours to write.
 *
 * THE BUG
 *
 * MySQL and MariaDB give the FIRST `timestamp` column in a table an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` unless
 * `explicit_defaults_for_timestamp` is on — which it is not in XAMPP. So three
 * columns that record when something HAPPENED were quietly being reset to the
 * current time on every UPDATE that did not name them:
 *
 *   tickets.opened_at            reset by every status transition, which
 *                                destroys the SLA baseline `due_at` was
 *                                computed against — a ticket acknowledged an
 *                                hour later looked like it had just been
 *                                raised. Confirmed on the live database:
 *                                00:52:02 became 01:04:58 from a status
 *                                change alone.
 *
 *   telemetry_hourly.hour_bucket the aggregator upserts, so every re-run
 *                                rewrote the bucket to `now()` — collapsing
 *                                distinct hours onto one value and then
 *                                colliding with the unique index. This is the
 *                                1062 in storage/logs/laravel.log.
 *
 *   telemetry_readings.recorded_at  only ever inserted today, so no damage
 *                                yet — but it is the same trap armed and
 *                                waiting for the first UPDATE anyone writes.
 *
 * WHY THE TESTS DID NOT CATCH IT
 *
 * SQLite has no such behaviour, and the suite runs on SQLite. This is the
 * third MariaDB-versus-SQLite gap in the project, after MySQL-only `FIELD()`
 * and `DATE_FORMAT`. Anything involving datetime columns needs checking
 * against MariaDB, not just a green suite.
 *
 * THE FIX
 *
 * `datetime` instead of `timestamp`. DATETIME never gets the implicit
 * default or the auto-update, and it is the more honest type here anyway:
 * these are moments we record, not a record of when the row last changed.
 * `created_at` / `updated_at` stay as they are — being touched automatically
 * is exactly their job.
 *
 * Values are preserved. MySQL returns a TIMESTAMP in the session timezone and
 * stores a DATETIME as that same literal, so what Laravel reads is unchanged.
 *
 * @see docs/progress/decision-log.md — 2026-08-29
 */
return new class extends Migration
{
    /**
     * @var array<string, string> table => column
     */
    private const COLUMNS = [
        'tickets' => 'opened_at',
        'telemetry_hourly' => 'hour_bucket',
        'telemetry_readings' => 'recorded_at',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dateTime($column)->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->timestamp($column)->nullable(false)->change();
            });
        }
    }
};
