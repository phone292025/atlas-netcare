<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit the LIVE database for traps the test suite cannot see.
 *
 * The suite runs on SQLite; the system runs on MariaDB. That gap has produced
 * three defects so far, every one of which passed a fully green suite:
 *
 *   - MySQL-only `FIELD()` in the ticket queue's ordering
 *   - MySQL-only `DATE_FORMAT` in telemetry aggregation
 *   - implicit `ON UPDATE CURRENT_TIMESTAMP` silently rewriting
 *     `tickets.opened_at` on every status transition
 *
 * READ ONLY. It runs SELECTs against information_schema and changes nothing,
 * so it is safe to point at the real database — which is the whole idea, since
 * a test using RefreshDatabase against the live database would wipe it.
 *
 * Run it before a submission, and after any migration.
 *
 * @see tests/Feature/SchemaIntegrityTest.php
 * @see docs/progress/decision-log.md — 2026-08-29
 */
class CheckSchema extends Command
{
    protected $signature = 'atlas:check-schema';

    protected $description = 'Audit the live database for MariaDB-specific schema traps';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->warn("Connected to {$driver}. These checks only mean anything on MySQL/MariaDB.");

            return self::SUCCESS;
        }

        $database = DB::connection()->getDatabaseName();
        $this->line("Auditing <fg=cyan>{$database}</> on {$driver}.");
        $this->newLine();

        $failures = $this->autoUpdatingTimestamps($database);

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} ".str('problem')->plural($failures).' found.');

            return self::FAILURE;
        }

        $this->info('Schema looks clean.');

        return self::SUCCESS;
    }

    /**
     * Columns MariaDB will silently rewrite on any UPDATE that does not name
     * them.
     *
     * MySQL and MariaDB give the FIRST `timestamp` column in a table an
     * implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` unless
     * `explicit_defaults_for_timestamp` is enabled — and it is not, in XAMPP.
     *
     * `created_at` and `updated_at` are exempt: being touched automatically is
     * their entire purpose.
     */
    private function autoUpdatingTimestamps(string $database): int
    {
        $offenders = DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND EXTRA LIKE '%on update%'
             AND COLUMN_NAME NOT IN ('created_at', 'updated_at')
             ORDER BY TABLE_NAME, COLUMN_NAME",
            [$database],
        );

        if ($offenders === []) {
            $this->line(' <fg=green>✓</> No domain timestamp auto-updates itself.');

            return 0;
        }

        $this->line(' <fg=red>✗</> These columns are rewritten on any UPDATE that does not name them:');

        foreach ($offenders as $column) {
            $this->line("     {$column->TABLE_NAME}.{$column->COLUMN_NAME}");
        }

        $this->newLine();
        $this->line('   Declare them as <fg=cyan>dateTime</> rather than <fg=cyan>timestamp</> in a new migration.');

        return count($offenders);
    }
}
