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
        $database = DB::connection()->getDatabaseName();

        $this->line("Auditing <fg=cyan>{$database}</> on {$driver}.");
        $this->newLine();

        $failures = 0;

        /*
         * The schema audit reads information_schema and is meaningless
         * anywhere else. The data checks below are plain portable SQL and run
         * everywhere — including under the test suite on SQLite, which is the
         * only way they can be tested at all. Returning early here would have
         * left them permanently unexercised.
         */
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $failures += $this->autoUpdatingTimestamps($database);
        } else {
            $this->warn('  Skipping the schema audit: it only means anything on MySQL/MariaDB.');
        }

        $this->newLine();

        $failures += $this->impossibleTimelines();

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} ".str('problem')->plural($failures).' found.');

            return self::FAILURE;
        }

        $this->info('Schema and data both look clean.');

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

    /**
     * Rows whose own timestamps contradict each other.
     *
     * THE SCHEMA CHECK ABOVE STOPS THE CAUSE; THIS FINDS THE DAMAGE. Changing
     * `tickets.opened_at` to `dateTime` on 2026-08-29 stopped MariaDB
     * rewriting it, but every row written before that fix kept whatever the
     * bug had already done to it. One ticket carried a `closed_at` eight hours
     * BEFORE its own `opened_at`, and nothing in the project noticed: the
     * suite was green, the schema audit was clean, and the screen showed a
     * plausible-looking date.
     *
     * These checks need no MySQL-specific SQL, so unlike the schema audit they
     * are meaningful on any driver.
     *
     * READ ONLY, like the rest of this command. It reports; repairing is a
     * decision for a person, because the right correction depends on which
     * column is the trustworthy one and that is not always obvious.
     */
    private function impossibleTimelines(): int
    {
        $this->line('  <options=bold>Timelines that cannot have happened</>');

        $checks = [
            [
                'A ticket resolved before it was opened',
                fn () => DB::table('tickets')
                    ->whereNotNull('resolved_at')
                    ->whereColumn('resolved_at', '<', 'opened_at'),
            ],
            [
                'A ticket closed before it was resolved',
                fn () => DB::table('tickets')
                    ->whereNotNull('closed_at')
                    ->whereNotNull('resolved_at')
                    ->whereColumn('closed_at', '<', 'resolved_at'),
            ],
            [
                'A ticket due before it was opened',
                fn () => DB::table('tickets')->whereColumn('due_at', '<', 'opened_at'),
            ],
            [
                'A ticket carrying a resolution it has not reached',
                // The state machine forbids this, so a row in this state was
                // written around the service rather than through it.
                fn () => DB::table('tickets')
                    ->whereNotNull('closed_at')
                    ->whereNotIn('status', ['closed']),
            ],
            [
                'An inspection completed before it was started',
                fn () => DB::table('inspections')
                    ->whereNotNull('completed_at')
                    ->whereColumn('completed_at', '<', 'started_at'),
            ],
            [
                'A shift that ends before it starts',
                fn () => DB::table('shifts')->whereColumn('end_time', '<=', 'start_time'),
            ],
            [
                'A risk assessment scored outside 0-100',
                fn () => DB::table('risk_assessments')
                    ->where(fn ($q) => $q->where('score', '<', 0)->orWhere('score', '>', 100)),
            ],
        ];

        $failures = 0;

        foreach ($checks as [$label, $query]) {
            $count = $query()->count();

            if ($count === 0) {
                $this->line("   <fg=green>✓</> {$label} — none");

                continue;
            }

            $failures++;
            $this->line("   <fg=red>✗</> {$label} — <options=bold>{$count}</>");
        }

        return $failures;
    }
}
