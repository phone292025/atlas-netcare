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
 * Turns an asset's history into a 0-100 score, a band, and a per-factor
 * breakdown saying WHY. "78, because it is five years old and has not been
 * inspected in six weeks" is the sentence ADR-0007 chose a weighted model over
 * a trained one to be able to produce.
 *
 * HOW IT WORKS. Each factor is a measurement divided by a ceiling from
 * `config('atlas.risk_inputs')`, clamped to 0..1, multiplied by its weight
 * from `config('atlas.risk_weights')` (the weights sum to 1.0 per asset type),
 * summed and scaled to 100. No hidden step, no learned parameter.
 *
 * TWO FACTOR SETS. A lab PC is scored on its history: age, faults, critical
 * inspection failures, inspection lag. An access point is scored on telemetry
 * TRENDS over the window, not on any single reading.
 *
 * MISSING DATA IS NOT ZERO RISK. A factor that cannot be measured is dropped
 * and the remaining weights renormalised; see combine().
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
            // Null, not 0, when the purchase date is unknown: an undated
            // machine is of unknown age, not new.
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
            // "We have no data" and "this is healthy" are different answers.
            return $this->unscored();
        }

        return $this->combine(config('atlas.risk_weights.access_point'), [
            // Trends, not levels: a consistently slow link is a known
            // quantity; one getting slower is the prediction.
            'latency_trend' => $this->trend($hours, 'avg_latency_ms') / $in['latency_ceiling_ms'],

            'packet_loss_trend' => $this->trend($hours, 'avg_packet_loss_pct') / $in['packet_loss_ceiling_pct'],

            'offline_events' => $this->offlineHours($hours) / $in['offline_ceiling'],

            'client_saturation' => $this->saturation($ap, $hours),
        ]);
    }

    /**
     * Score one asset and persist it.
     *
     * The assessment and the asset's cached columns are written in one
     * transaction, or the ranking (which reads the cache) and the detail page
     * (which reads the assessment) disagree until the next run.
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

            // forceFill: the risk columns are system-written, never fillable.
            // Nothing measurable leaves them empty: a stored 0 and Low ranked
            // and counted a silent device as the healthiest on campus.
            $measured = $result['factors'] !== [];

            $asset->forceFill([
                'risk_score' => $measured ? $result['score'] : null,
                'risk_band' => $measured ? $result['band'] : null,
            ])->save();

            return $assessment;
        });
    }

    /**
     * Score every asset in service, as one run.
     *
     * One timestamp for the whole run, so "Tuesday's run" can be selected as
     * a unit. Retired machines are skipped: ranking a machine nobody can
     * maintain on a maintenance priority screen is noise.
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
     * A null factor cannot be measured, so its weight leaves the denominator
     * rather than counting as zero: otherwise a machine missing its purchase
     * date would rank lower than an identical one with a date, only because
     * nobody typed it in.
     *
     * Each factor is clamped to 0..1 first, so one extreme input cannot take
     * more than its share. The stored contributions are rescaled with the
     * score, so the breakdown still adds up to the number shown.
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
     * Nothing measurable. The empty breakdown is the signal: the screen shows
     * "not enough data", and a genuine zero always has factors behind it.
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
     * Proportion of CRITICAL checklist items this machine failed in the
     * window. Critical only: a wobbly stand and a failing disk are both
     * failures, and only one predicts the machine dying.
     *
     * Null when not inspected in the window: unknown, not passing.
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
     * Days since this machine was last inspected. Never inspected returns the
     * ceiling, not null: "never looked at" is the strongest form of this
     * signal, and treating it as unmeasurable would reward neglect.
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
     * Hourly rollups for the trend window, oldest first. Two weeks of raw
     * minutes is twenty thousand rows per access point, and an hourly mean is
     * the right resolution for "is this getting worse".
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
     * How much a measure WORSENED across the window: the second half's mean
     * minus the first half's. One subtraction anyone can check by hand rather
     * than a regression. Improvement returns 0, not a negative that would
     * offset a real problem elsewhere.
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
     * Hours in the window when the access point was not fully up. The rollup
     * does not record individual drops, so an hour is the honest unit.
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
     * Peak client load as a proportion of capacity: the peak, because an
     * access point saturated during the 11:00 session is a problem at 11:00,
     * whatever its fortnightly average says. Null without a capacity.
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
