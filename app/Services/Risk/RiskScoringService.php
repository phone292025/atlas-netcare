<?php

namespace App\Services\Risk;

use App\Enums\RiskBand;
use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\RiskAssessment;
use App\Models\TelemetryHourly;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The predictive part of the project.
 *
 * Turns an asset's history into a 0-100 score, a band, and — the part that
 * matters — a per-factor breakdown saying WHY. A bare number is monitoring.
 * "78, because it is five years old and has not been inspected in six weeks"
 * is the analytics the case study category asks for, and ADR-0007 chose a
 * weighted model over a trained one specifically so that sentence can be
 * produced and defended.
 *
 * HOW IT WORKS, in one paragraph you can say out loud. Each factor is a raw
 * measurement divided by a ceiling from `config('atlas.risk_inputs')`, giving
 * a number from 0 to 1, clamped so nothing can exceed its share. Each is
 * multiplied by its weight from `config('atlas.risk_weights')`, which sum to
 * 1.0 per asset type. The weighted values are summed and multiplied by 100.
 * There is no hidden step and no learned parameter.
 *
 * TWO FACTOR SETS, because the two asset types leave different evidence. A
 * lab PC is scored on history: age, fault frequency, critical inspection
 * failures, inspection lag. An access point has telemetry, so it is scored on
 * TRENDS — a link degrading over two weeks — rather than on any single
 * reading, which is weather rather than climate.
 *
 * MISSING DATA IS NOT ZERO RISK. A machine with no purchase date is not new.
 * Every factor that cannot be measured is dropped and the remaining weights
 * are renormalised, so a partially-known asset is scored on what IS known
 * rather than diluted toward zero by silence. See combine().
 *
 * @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
 * @see docs/modules/M7-predictive-and-reports.md
 */
class RiskScoringService
{
    /**
     * Score a lab PC without saving anything.
     *
     * @return array{score: float, band: RiskBand, factors: array<string, float>}
     */
    public function scoreLabPc(LabPc $pc): array
    {
        $in = config('atlas.risk_inputs');
        $age = $pc->ageInYears();

        return $this->combine(config('atlas.risk_weights.lab_pc'), [
            // Null rather than 0 when the purchase date is unknown: an
            // undated machine is of unknown age, not a new one.
            'age' => $age === null ? null : $age / $in['pc_life_years'],

            'fault_frequency' => $this->faultCount($pc, (int) $in['fault_window_days']) / $in['fault_ceiling'],

            'critical_fail_rate' => $this->criticalFailRate($pc),

            'days_since_inspect' => $this->daysSinceInspection($pc) / $in['inspect_ceiling_days'],
        ]);
    }

    /**
     * Score an access point without saving anything.
     *
     * @return array{score: float, band: RiskBand, factors: array<string, float>}
     */
    public function scoreAccessPoint(AccessPoint $ap): array
    {
        $in = config('atlas.risk_inputs');
        $hours = $this->telemetryWindow($ap, (int) $in['trend_days']);

        if ($hours->isEmpty()) {
            // No telemetry at all, so every factor is unmeasurable and
            // combine() would have nothing to renormalise against. Reported
            // as unscored rather than as a zero, because "we have no data"
            // and "this is healthy" are different answers.
            return $this->unscored();
        }

        return $this->combine(config('atlas.risk_weights.access_point'), [
            // Trends, not levels. A consistently slow link is a known
            // quantity; one that is getting slower is the prediction.
            'latency_trend' => $this->trend($hours, 'avg_latency_ms') / $in['latency_ceiling_ms'],

            'packet_loss_trend' => $this->trend($hours, 'avg_packet_loss_pct') / $in['packet_loss_ceiling_pct'],

            'offline_events' => $this->offlineHours($hours) / $in['offline_ceiling'],

            'client_saturation' => $this->saturation($ap, $hours),
        ]);
    }

    /**
     * Score one asset and persist it.
     *
     * The assessment row and the asset's cached columns are written in ONE
     * transaction. Writing either alone leaves the ranking screen, which
     * reads the cache, disagreeing with the detail screen, which reads the
     * assessment — and they would keep disagreeing until the next run.
     */
    public function assess(LabPc|AccessPoint $asset, ?Carbon $at = null): RiskAssessment
    {
        $at ??= now();

        $result = $asset instanceof LabPc
            ? $this->scoreLabPc($asset)
            : $this->scoreAccessPoint($asset);

        return DB::transaction(function () use ($asset, $result, $at) {
            $assessment = $asset->riskAssessments()->create([
                'score' => $result['score'],
                'band' => $result['band'],
                'factors' => $result['factors'],
                'calculated_at' => $at,
            ]);

            // forceFill, because risk_score and risk_band are deliberately
            // absent from $fillable — written by the system, never by a form.
            // Same rule as `role` on User.
            $asset->forceFill([
                'risk_score' => $result['score'],
                'risk_band' => $result['band'],
            ])->save();

            return $assessment;
        });
    }

    /**
     * Score every asset in service, as one run.
     *
     * ONE TIMESTAMP FOR THE WHOLE RUN, passed down rather than taken per
     * asset. Scoring a few hundred machines takes seconds, so per-asset
     * timestamps would smear one run across several of them and there would
     * be no way to select "Tuesday's run" as a unit.
     *
     * Retired machines are skipped. They are kept for their history, and
     * ranking a machine nobody can maintain any more on a maintenance
     * priority screen is noise.
     *
     * @return array{lab_pcs: int, access_points: int, at: Carbon}
     */
    public function recalculateAll(?Carbon $at = null): array
    {
        $at ??= now();
        $counts = ['lab_pcs' => 0, 'access_points' => 0, 'at' => $at];

        LabPc::inService()->chunkById(100, function (Collection $pcs) use ($at, &$counts): void {
            foreach ($pcs as $pc) {
                $this->assess($pc, $at);
                $counts['lab_pcs']++;
            }
        });

        AccessPoint::query()->chunkById(100, function (Collection $aps) use ($at, &$counts): void {
            foreach ($aps as $ap) {
                $this->assess($ap, $at);
                $counts['access_points']++;
            }
        });

        return $counts;
    }

    /**
     * Weighted sum of the measurable factors, scaled to 0-100.
     *
     * A null factor means "cannot be measured", and its weight is removed
     * from the denominator rather than counted as zero. Without that, a
     * machine missing its purchase date would score 25% lower than an
     * identical machine that has one, and would sit lower in a maintenance
     * ranking for the sole reason that nobody typed a date in.
     *
     * Each factor is clamped to 0..1 before weighting, so one extreme input
     * cannot take more than its allotted share. A machine ten years past its
     * expected life contributes the same age risk as one six years past —
     * both are "as old as this factor goes".
     *
     * The stored contributions are rescaled alongside the score so they still
     * sum to the number the user is shown. A breakdown that does not add up
     * to its own total is worse than no breakdown.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, float|null>  $raw
     * @return array{score: float, band: RiskBand, factors: array<string, float>}
     */
    private function combine(array $weights, array $raw): array
    {
        $factors = [];
        $available = 0.0;

        foreach ($raw as $name => $value) {
            if ($value === null) {
                continue;
            }

            $weight = (float) ($weights[$name] ?? 0.0);
            $available += $weight;

            $factors[$name] = max(0.0, min(1.0, (float) $value)) * $weight * 100;
        }

        if ($available <= 0.0) {
            return $this->unscored();
        }

        $subtotal = array_sum($factors);
        $score = round($subtotal / $available, 2);

        $scale = $subtotal > 0.0 ? $score / $subtotal : 0.0;

        foreach ($factors as $name => $contribution) {
            $factors[$name] = round($contribution * $scale, 2);
        }

        return [
            'score' => $score,
            'band' => RiskBand::fromScore($score),
            'factors' => $factors,
        ];
    }

    /**
     * Nothing measurable.
     *
     * Zero with an empty breakdown, which the UI renders as "not enough data"
     * rather than as a clean bill of health. The empty factors array is the
     * signal — a genuine zero score always has factors behind it.
     *
     * @return array{score: float, band: RiskBand, factors: array<string, float>}
     */
    private function unscored(): array
    {
        return ['score' => 0.0, 'band' => RiskBand::Low, 'factors' => []];
    }

    /** Tickets raised against this asset inside the window. */
    private function faultCount(LabPc|AccessPoint $asset, int $days): int
    {
        return $asset->tickets()
            ->where('opened_at', '>=', now()->subDays($days))
            ->count();
    }

    /**
     * Proportion of CRITICAL checklist items this machine has failed lately.
     *
     * Critical items only. A wobbly monitor stand and a failing disk are both
     * checklist failures; only one of them predicts the machine dying, and
     * `checklist_items.is_critical` is the column that says which.
     *
     * Null when the machine has not been inspected in the window — unknown,
     * not passing.
     */
    private function criticalFailRate(LabPc $pc): ?float
    {
        $window = now()->subDays((int) config('atlas.risk_inputs.fault_window_days'));

        $row = DB::table('inspection_results')
            ->join('inspections', 'inspections.id', '=', 'inspection_results.inspection_id')
            ->join('checklist_items', 'checklist_items.id', '=', 'inspection_results.checklist_item_id')
            ->where('inspections.lab_pc_id', $pc->id)
            ->where('checklist_items.is_critical', true)
            ->whereNotNull('inspections.completed_at')
            ->where('inspections.completed_at', '>=', $window)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN inspection_results.result = ? THEN 1 ELSE 0 END) as failed', ['issue'])
            ->first();

        if (! $row || (int) $row->total === 0) {
            return null;
        }

        return (int) $row->failed / (int) $row->total;
    }

    /**
     * Days since this machine was last inspected.
     *
     * A machine that has NEVER been inspected returns the ceiling rather than
     * null, deliberately. "Never looked at" is the strongest version of the
     * signal this factor measures, not an absence of it — treating it as
     * unmeasurable would quietly reward neglect.
     */
    private function daysSinceInspection(LabPc $pc): float
    {
        $ceiling = (float) config('atlas.risk_inputs.inspect_ceiling_days');

        $last = $pc->last_inspected_at
            ?? $pc->inspections()->whereNotNull('completed_at')->max('completed_at');

        if (! $last) {
            return $ceiling;
        }

        return (float) Carbon::parse($last)->diffInDays(now());
    }

    /**
     * Hourly rollups for the trend window, oldest first.
     *
     * Aggregates rather than raw readings, deliberately: two weeks of
     * per-minute rows is around twenty thousand per access point, and an
     * hourly mean is the right resolution for "is this getting worse" anyway.
     *
     * @return Collection<int, TelemetryHourly>
     */
    private function telemetryWindow(AccessPoint $ap, int $days): Collection
    {
        return $ap->hourly()
            ->where('hour_bucket', '>=', now()->subDays($days))
            ->orderBy('hour_bucket')
            ->get();
    }

    /**
     * How much a measure has WORSENED across the window.
     *
     * The second half's mean minus the first half's. Deliberately not a least
     * squares regression: this is one subtraction anybody on the team can
     * explain and check by hand, and over two weeks of hourly data it answers
     * the same question. ADR-0007's whole argument is that every number has
     * to survive being questioned.
     *
     * Improvement returns 0 rather than a negative. A link getting better is
     * not negative risk that offsets a real problem elsewhere.
     *
     * @param  Collection<int, TelemetryHourly>  $hours
     */
    private function trend(Collection $hours, string $column): float
    {
        if ($hours->count() < 2) {
            return 0.0;
        }

        $half = (int) floor($hours->count() / 2);

        $early = (float) $hours->take($half)->avg($column);
        $late = (float) $hours->slice($half)->avg($column);

        return max(0.0, $late - $early);
    }

    /**
     * Hours in the window where the access point was not fully up.
     *
     * `uptime_pct` below 100 for an hour counts as one. Coarse on purpose:
     * the aggregate does not record individual drops, so an hour with any
     * downtime is the honest unit this data supports.
     *
     * @param  Collection<int, TelemetryHourly>  $hours
     */
    private function offlineHours(Collection $hours): int
    {
        return $hours
            ->filter(fn ($h) => $h->uptime_pct !== null && (float) $h->uptime_pct < 100.0)
            ->count();
    }

    /**
     * Peak client load as a proportion of capacity.
     *
     * The peak, not the mean: an access point that is fine all day and
     * saturated during the 11:00 lab session is a problem at 11:00, and an
     * average across two weeks hides exactly that.
     *
     * Null when capacity is unknown, because the proportion is meaningless
     * without it.
     *
     * @param  Collection<int, TelemetryHourly>  $hours
     */
    private function saturation(AccessPoint $ap, Collection $hours): ?float
    {
        if (! $ap->client_capacity) {
            return null;
        }

        $peak = $hours->max('peak_client_count');

        return $peak === null ? null : (float) $peak / (float) $ap->client_capacity;
    }
}
