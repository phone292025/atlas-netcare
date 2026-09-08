<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disarm the rest of the MariaDB timestamp trap, rather than leaving it loaded.
 *
 * WHAT THE EARLIER MIGRATION DID.
 * `2026_08_29_010001_stop_mariadb_auto_updating_domain_timestamps` converted
 * the three columns that had already been damaged: `tickets.opened_at`,
 * `telemetry_hourly.hour_bucket` and `telemetry_readings.recorded_at`. It fixed
 * the bug in front of it and stopped there, which was the right call that day.
 *
 * WHAT IT LEFT. Eight more columns recording when something happened are still
 * declared `timestamp`:
 *
 *   tickets.due_at, acknowledged_at, resolved_at, closed_at
 *   inspections.started_at, completed_at
 *   lab_pcs.last_inspected_at
 *   access_points.last_seen_at
 *
 * None of them auto-updates today, and `atlas:check-schema` confirms it. That
 * is not because they are safe — it is because MariaDB only hands the implicit
 * `ON UPDATE CURRENT_TIMESTAMP` to a NOT NULL timestamp column, and every one
 * of these happens to be nullable. The protection is a property of the null
 * flag, not of the design, so the day somebody decides `started_at` should
 * never be null the trap re-arms silently and `atlas:check-schema` reports it
 * only after the data has been rewritten.
 *
 * AGENTS.md states the rule without an exception: never use `timestamp` for a
 * column recording when something happened. Eight columns disagreed with it.
 *
 * SAFE ON EXISTING DATA. MySQL returns a TIMESTAMP in the session timezone and
 * stores a DATETIME as that same literal, so the values Laravel reads are
 * unchanged. Nullability is preserved exactly. This is the same conversion the
 * 2026-08-29 migration already performed on three columns of live data.
 *
 * @see docs/04-database.md
 * @see docs/progress/decision-log.md
 */
return new class extends Migration
{
    /**
     * table => [column => nullable]
     *
     * @var array<string, array<string, bool>>
     */
    private const COLUMNS = [
        'tickets' => [
            'due_at' => true,
            'acknowledged_at' => true,
            'resolved_at' => true,
            'closed_at' => true,
        ],
        'inspections' => [
            'started_at' => true,
            'completed_at' => true,
        ],
        'lab_pcs' => [
            'last_inspected_at' => true,
        ],
        'access_points' => [
            'last_seen_at' => true,
        ],
    ];

    public function up(): void
    {
        $this->convert('dateTime');
    }

    public function down(): void
    {
        $this->convert('timestamp');
    }

    private function convert(string $type): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $type): void {
                foreach ($columns as $column => $nullable) {
                    $blueprint->{$type}($column)->nullable($nullable)->change();
                }
            });
        }
    }
};
