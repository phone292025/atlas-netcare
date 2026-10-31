<?php

namespace Tests\Unit;

use App\Enums\RiskBand;
use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\RiskAssessment;
use App\Models\TelemetryReading;
use App\Services\Risk\RiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scoring maths, factor by factor.
 *
 * This is the most test-worthy code in the project and the part most likely
 * to be questioned, so each test isolates ONE factor by neutralising the
 * others — otherwise a passing total tells you nothing about which term
 * produced it, and a sign error in one factor hides inside a plausible sum.
 *
 * Weights are pinned inside the tests rather than read from config. A test
 * that reads the same configuration as the code cannot detect a weight
 * changing, which is the exact thing these numbers need protecting from.
 *
 * @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
 */
class RiskScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private RiskScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RiskScoringService;

        /*
         * Pinned so the assertions below mean something, and weighted so each
         * factor is readable by hand: age carries half, inspection lag most of
         * the rest.
         *
         * critical_fail_rate is deliberately weighted ZERO. It is unmeasurable
         * on a machine with no completed inspections, and an unmeasurable
         * factor is dropped from the denominator — so at any other weight it
         * would renormalise every number below by 1/(1 - its weight) and the
         * arithmetic would stop being checkable by eye. Its own behaviour is
         * covered by the renormalising tests.
         */
        config([
            'atlas.risk_weights.lab_pc' => [
                'age' => 0.50,
                'fault_frequency' => 0.20,
                'critical_fail_rate' => 0.00,
                'days_since_inspect' => 0.30,
            ],
            'atlas.risk_inputs.pc_life_years' => 6,
            'atlas.risk_inputs.fault_window_days' => 90,
            'atlas.risk_inputs.fault_ceiling' => 10,
            'atlas.risk_inputs.inspect_ceiling_days' => 30,
        ]);
    }

    public function test_a_brand_new_machine_inspected_today_scores_zero(): void
    {
        $pc = LabPc::factory()->create([
            'purchase_date' => now(),
            'last_inspected_at' => now(),
        ]);

        $result = $this->service->scoreLabPc($pc);

        // Delta rather than exactly zero: purchase_date is a DATE, so a
        // machine bought "today" is already a few hours old by the afternoon
        // and contributes a rounding crumb. Zero to the nearest tenth.
        $this->assertEqualsWithDelta(0.0, $result['score'], 0.05);
        $this->assertSame(RiskBand::Low, $result['band']);
    }

    public function test_age_alone_contributes_its_weight(): void
    {
        /*
         * Three years into a six-year life is exactly half the age factor.
         * Age carries 0.5, so half of it is 25 points and nothing else can
         * contribute: inspected today, no tickets, never inspected in a way
         * that recorded a critical failure.
         */
        $pc = LabPc::factory()->create([
            'purchase_date' => now()->subYears(3),
            'last_inspected_at' => now(),
        ]);

        $result = $this->service->scoreLabPc($pc);

        $this->assertEqualsWithDelta(25.0, $result['score'], 0.5);
        $this->assertEqualsWithDelta(25.0, $result['factors']['age'], 0.5);
    }

    public function test_a_factor_cannot_exceed_its_share_however_extreme_the_input(): void
    {
        /*
         * Twenty years into a six-year life is 3.3x the ceiling. Clamped, it
         * is worth exactly the same as six years — "as old as this factor
         * goes". Without the clamp one ancient machine would swamp the whole
         * ranking and every other factor would stop mattering.
         */
        $ancient = LabPc::factory()->create([
            'purchase_date' => now()->subYears(20),
            'last_inspected_at' => now(),
        ]);

        $expired = LabPc::factory()->create([
            'purchase_date' => now()->subYears(6),
            'last_inspected_at' => now(),
        ]);

        $this->assertEqualsWithDelta(
            $this->service->scoreLabPc($expired)['score'],
            $this->service->scoreLabPc($ancient)['score'],
            0.5,
        );

        $this->assertEqualsWithDelta(50.0, $this->service->scoreLabPc($ancient)['score'], 0.5);
    }

    public function test_an_unknown_purchase_date_is_not_treated_as_new(): void
    {
        /*
         * The renormalising rule, and the one most likely to be got wrong.
         * Counting a missing date as zero risk would make an undated machine
         * score 50 points lower than an identical dated one and sink it in
         * the ranking, purely because nobody typed a date in.
         *
         * Both machines below are three weeks past an inspection. With age
         * dropped, the remaining weights sum to 0.5 and the inspection factor
         * is renormalised against that, so it lands at its full share of what
         * can actually be measured.
         */
        $undated = LabPc::factory()->create([
            'purchase_date' => null,
            'last_inspected_at' => now()->subDays(21),
        ]);

        $result = $this->service->scoreLabPc($undated);

        $this->assertArrayNotHasKey('age', $result['factors'], 'An unmeasurable factor must not appear in the breakdown.');

        /*
         * Age is dropped, so the measurable weight is fault_frequency plus
         * days_since_inspect, 0.5 between them. The inspection factor is
         * 21/30 of its 0.3 weight, renormalised onto that 0.5:
         *
         *     0.7 * 0.30 / 0.50 * 100 = 42
         *
         * Note it scores HIGHER than the same machine with a known date
         * would. Missing evidence does not lower a score; the score simply
         * reflects only what is known.
         */
        $this->assertEqualsWithDelta(42.0, $result['score'], 1.0);
    }

    public function test_a_machine_never_inspected_carries_the_full_inspection_factor(): void
    {
        /*
         * Never inspected is the STRONGEST version of this signal, not an
         * absence of it. Treating it as unmeasurable would quietly reward
         * neglect: the machines nobody has ever looked at would drop out of
         * the maintenance ranking.
         */
        $pc = LabPc::factory()->create([
            'purchase_date' => now(),
            'last_inspected_at' => null,
        ]);

        $result = $this->service->scoreLabPc($pc);

        $this->assertArrayHasKey('days_since_inspect', $result['factors']);
        $this->assertEqualsWithDelta(30.0, $result['factors']['days_since_inspect'], 0.5);
    }

    public function test_the_breakdown_always_sums_to_the_score(): void
    {
        /*
         * The breakdown is shown to the user underneath the number. If the
         * parts do not add up to the total, the screen is visibly wrong and
         * the explanation is worse than no explanation.
         */
        $pc = LabPc::factory()->create([
            'purchase_date' => now()->subYears(4),
            'last_inspected_at' => now()->subDays(11),
        ]);

        $result = $this->service->scoreLabPc($pc);

        $this->assertEqualsWithDelta($result['score'], array_sum($result['factors']), 0.05);
    }

    public function test_the_breakdown_still_sums_when_a_factor_was_dropped(): void
    {
        // Renormalising rescales the contributions as well as the total. This
        // is the case where getting that wrong is invisible in the score and
        // obvious on the screen.
        $pc = LabPc::factory()->create([
            'purchase_date' => null,
            'last_inspected_at' => now()->subDays(9),
        ]);

        $result = $this->service->scoreLabPc($pc);

        $this->assertEqualsWithDelta($result['score'], array_sum($result['factors']), 0.05);
    }

    public function test_scores_land_in_the_configured_bands(): void
    {
        config(['atlas.risk_bands' => ['low' => 0, 'medium' => 40, 'high' => 65, 'critical' => 85]]);

        $this->assertSame(RiskBand::Low, RiskBand::fromScore(39.9));
        $this->assertSame(RiskBand::Medium, RiskBand::fromScore(40));
        $this->assertSame(RiskBand::Medium, RiskBand::fromScore(64.9));
        $this->assertSame(RiskBand::High, RiskBand::fromScore(65));
        $this->assertSame(RiskBand::Critical, RiskBand::fromScore(85));
    }

    public function test_an_access_point_with_no_telemetry_is_unscored_rather_than_healthy(): void
    {
        /*
         * A silent access point is usually a dead one. Scoring it 0 would
         * rank it as the healthiest device on campus, which is exactly
         * backwards. The empty breakdown is what the UI reads to say "not
         * enough data" instead of showing a clean bill of health.
         */
        $ap = AccessPoint::factory()->create();

        $result = $this->service->scoreAccessPoint($ap);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame([], $result['factors']);

        // And the ranking reads the asset's own columns, so they stay empty.
        $this->service->assess($ap);
        $this->assertNull($ap->fresh()->risk_score);
        $this->assertNull($ap->fresh()->risk_band);
    }

    public function test_a_worsening_link_scores_above_a_steadily_bad_one(): void
    {
        /*
         * The whole argument for M7 existing. A link that has always been
         * slow is a known quantity somebody has already decided to live with.
         * A link that is GETTING slower is the prediction, and it has to rank
         * higher even when its absolute numbers are lower.
         */
        config([
            'atlas.risk_weights.access_point' => [
                'latency_trend' => 1.0,
                'packet_loss_trend' => 0.0,
                'offline_events' => 0.0,
                'client_saturation' => 0.0,
            ],
            'atlas.risk_inputs.trend_days' => 14,
            'atlas.risk_inputs.latency_ceiling_ms' => 120,
        ]);

        $degrading = $this->apWithLatency([10, 10, 10, 10, 70, 70, 70, 70]);
        $consistentlyBad = $this->apWithLatency([90, 90, 90, 90, 90, 90, 90, 90]);

        $this->assertGreaterThan(
            $this->service->scoreAccessPoint($consistentlyBad)['score'],
            $this->service->scoreAccessPoint($degrading)['score'],
        );
    }

    public function test_an_improving_link_is_not_negative_risk(): void
    {
        // Improvement must floor at zero. A link recovering must not offset
        // a genuine problem in another factor and drag the total down.
        config([
            'atlas.risk_weights.access_point' => [
                'latency_trend' => 1.0,
                'packet_loss_trend' => 0.0,
                'offline_events' => 0.0,
                'client_saturation' => 0.0,
            ],
        ]);

        $recovering = $this->apWithLatency([90, 90, 90, 90, 10, 10, 10, 10]);

        $this->assertSame(0.0, $this->service->scoreAccessPoint($recovering)['score']);
    }

    public function test_assessing_writes_the_history_row_and_the_cached_columns_together(): void
    {
        /*
         * The ranking screen reads the cached column and the detail screen
         * reads the assessment. Writing one without the other makes the two
         * disagree about the same machine until the next run.
         */
        $pc = LabPc::factory()->create([
            'purchase_date' => now()->subYears(5),
            'last_inspected_at' => now()->subDays(20),
        ]);

        $assessment = $this->service->assess($pc);

        $this->assertEqualsWithDelta($assessment->score, $pc->fresh()->risk_score, 0.01);
        $this->assertSame($assessment->band, $pc->fresh()->risk_band);
        $this->assertSame($assessment->factors, $pc->fresh()->latestRiskAssessment->factors);
    }

    public function test_a_run_shares_one_timestamp_across_every_asset(): void
    {
        /*
         * Scoring hundreds of assets takes seconds. Per-asset timestamps
         * would smear one run across several of them, and "show me Tuesday's
         * run" would stop being answerable.
         */
        LabPc::factory()->count(3)->create();
        AccessPoint::factory()->count(2)->create();

        $counts = $this->service->recalculateAll();

        $this->assertSame(3, $counts['lab_pcs']);
        $this->assertSame(2, $counts['access_points']);

        $stamps = RiskAssessment::query()->distinct()->pluck('calculated_at');

        $this->assertCount(1, $stamps, 'One run must produce exactly one calculated_at.');
    }

    public function test_a_retired_machine_is_not_ranked(): void
    {
        // Kept for its history, but ranking a machine nobody can maintain any
        // more on a maintenance priority screen is noise.
        LabPc::factory()->create();
        LabPc::factory()->retired()->create();

        $counts = $this->service->recalculateAll();

        $this->assertSame(1, $counts['lab_pcs']);
    }

    /**
     * An access point whose hourly latency readings follow the given series,
     * one per hour, oldest first.
     *
     * @param  list<int|float>  $latencies
     */
    private function apWithLatency(array $latencies): AccessPoint
    {
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);

        // One reading in each hour, which that hour's summary is derived from.
        foreach (array_values($latencies) as $i => $ms) {
            TelemetryReading::factory()->create([
                'network_device_id' => $ap->id,
                'recorded_at' => now()->subHours(count($latencies) - $i)->startOfHour()->addMinutes(10),
                'latency_ms' => $ms,
                'client_count' => 0,
                'packet_loss_pct' => 0,
                'channel_util_pct' => 0,
                'status' => 'online',
            ]);
        }

        return $ap;
    }
}
