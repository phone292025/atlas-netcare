<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One predictive risk score for one asset, at one moment.
 *
 * HISTORY, NOT A CURRENT VALUE. A new row per asset per run rather than an
 * overwrite. That costs a few thousand rows and buys the risk-trend chart —
 * an asset climbing from 40 to 78 over a month is the figure that shows this
 * is prediction rather than a snapshot, and it is the strongest thing M7 has
 * to put in the report.
 *
 * The current value lives on `lab_pcs.risk_score` / `access_points.risk_score`
 * as a deliberate cache, so a list of two hundred machines does not need a
 * correlated subquery to sort by risk. RiskScoringService writes both in one
 * transaction; anything that writes one without the other makes list views
 * and detail views disagree.
 *
 * WHY THE FACTORS ARE STORED. A bare score is not analytics. ADR-0007 chose a
 * weighted model over a trained one precisely so the system can say "78,
 * because it is five years old and has not been inspected in six weeks", and
 * that sentence needs the per-factor contributions kept alongside the total.
 * They are stored as they were at the time, so a score calculated under last
 * month's weights still explains itself.
 *
 * @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
 * @see docs/modules/M7-predictive-and-reports.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table): void {
            $table->id();

            /*
             * Polymorphic, because lab PCs and access points are scored by the
             * same service into the same shape and ranked against each other
             * on one screen. Two tables would mean two of everything and a
             * union to rank them.
             */
            $table->morphs('assessable');

            // 0-100 with one decimal kept. Rounding to an integer here would
            // make two assets that differ by 0.4 look identical in a ranking.
            $table->decimal('score', 5, 2);

            $table->string('band', 20);

            /*
             * name => weighted contribution, summing to `score`. Stored rather
             * than recomputed because the weights are tunable: recomputing
             * would explain an old score with today's configuration.
             *
             * MariaDB implements JSON as LONGTEXT with a check constraint
             * rather than a native type. Fine for storing and reading whole;
             * do not expect to query inside it.
             */
            $table->json('factors');

            /*
             * dateTime, NOT timestamp. MySQL and MariaDB give the first
             * `timestamp` column in a table an implicit
             * DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP unless
             * explicit_defaults_for_timestamp is on, which it is not in XAMPP.
             * That silently rewrote three columns before it was caught, one of
             * them destroying the SLA baseline on every ticket transition.
             *
             * This column IS the x-axis of the trend chart. A value that
             * quietly moves to "now" on any update would flatten it.
             *
             * @see docs/progress/decision-log.md — 2026-08-29
             */
            $table->dateTime('calculated_at');

            $table->timestamps();

            // The trend chart reads one asset's history in date order, which
            // is exactly this index. morphs() already indexed the pair.
            $table->index(['assessable_type', 'assessable_id', 'calculated_at'], 'risk_assessments_history_index');

            // "Everything from the last run", for the ranking screen and for
            // reporting how a whole run turned out.
            $table->index('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
