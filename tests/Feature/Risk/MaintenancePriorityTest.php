<?php

namespace Tests\Feature\Risk;

use App\Enums\RiskBand;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The maintenance priority screens (FR-309, FR-310, FR-311).
 *
 * Every test here LOADS a page rather than only asserting a redirect or a
 * 403. M3 shipped three 500 errors onto routes that all had tests, every one
 * of them asserting a failure — nothing had ever fetched those pages
 * successfully. Empty states are covered as well as populated ones, because
 * empty is what every screen looks like on the day it ships.
 *
 * @see docs/modules/M7-predictive-and-reports.md
 */
class MaintenancePriorityTest extends TestCase
{
    use RefreshDatabase;

    private function engineer(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Engineer, 'is_active' => true])->save();

        return $user;
    }

    private function assistant(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        return $user;
    }

    public function test_the_ranking_renders_before_anything_has_been_scored(): void
    {
        // The state the screen is in on the day it ships, and the one most
        // likely to 500 on a null score.
        LabPc::factory()->count(3)->create(['risk_score' => null, 'risk_band' => null]);

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.index'))
            ->assertOk()
            ->assertSee('Maintenance priority')
            ->assertSee('Nothing has been scored yet');
    }

    public function test_the_ranking_puts_the_highest_score_first(): void
    {
        $low = LabPc::factory()->create(['asset_tag' => 'APU-LOW', 'risk_score' => 12, 'risk_band' => RiskBand::Low]);
        $high = LabPc::factory()->create(['asset_tag' => 'APU-HIGH', 'risk_score' => 88, 'risk_band' => RiskBand::Critical]);

        RiskAssessment::factory()->forAsset($high)->score(88)->create();
        RiskAssessment::factory()->forAsset($low)->score(12)->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.index'))
            ->assertOk()
            ->assertSeeInOrder(['APU-HIGH', 'APU-LOW']);
    }

    public function test_lab_pcs_and_access_points_are_ranked_against_each_other(): void
    {
        /*
         * One queue, not two. They are scored onto the same 0-100 scale
         * precisely so they can be compared, and a maintenance ranking split
         * across two tables you had to merge by eye would not be a priority
         * order at all.
         */
        $pc = LabPc::factory()->create(['asset_tag' => 'APU-MID', 'risk_score' => 50, 'risk_band' => RiskBand::Medium]);
        $ap = AccessPoint::factory()->create(['ap_code' => 'AP-TOP', 'risk_score' => 90, 'risk_band' => RiskBand::Critical]);

        RiskAssessment::factory()->forAsset($pc)->score(50)->create();
        RiskAssessment::factory()->forAsset($ap)->score(90)->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.index'))
            ->assertOk()
            ->assertSeeInOrder(['AP-TOP', 'APU-MID']);
    }

    public function test_an_unscored_asset_sorts_last_rather_than_first(): void
    {
        /*
         * Never scored is not low risk — nothing is known. Sorting a null to
         * the top would bury the assets that are genuinely about to fail
         * underneath the ones nobody has measured.
         */
        LabPc::factory()->create(['asset_tag' => 'APU-UNKNOWN', 'risk_score' => null, 'risk_band' => null]);
        $scored = LabPc::factory()->create(['asset_tag' => 'APU-SCORED', 'risk_score' => 30, 'risk_band' => RiskBand::Low]);
        RiskAssessment::factory()->forAsset($scored)->score(30)->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.index'))
            ->assertOk()
            ->assertSeeInOrder(['APU-SCORED', 'APU-UNKNOWN'])
            ->assertSee('Not scored');
    }

    public function test_the_ranking_filters_by_band(): void
    {
        $critical = LabPc::factory()->create(['asset_tag' => 'APU-CRIT', 'risk_score' => 90, 'risk_band' => RiskBand::Critical]);
        LabPc::factory()->create(['asset_tag' => 'APU-CALM', 'risk_score' => 10, 'risk_band' => RiskBand::Low]);
        RiskAssessment::factory()->forAsset($critical)->score(90)->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.index', ['band' => 'critical']))
            ->assertOk()
            ->assertSee('APU-CRIT')
            ->assertDontSee('APU-CALM');
    }

    public function test_the_detail_screen_shows_the_reasons_not_just_the_number(): void
    {
        /*
         * FR-310, and the difference between monitoring and analytics. A bare
         * score could have come from a threshold rule; the breakdown is what
         * ADR-0007 chose a weighted model to be able to show.
         */
        $pc = LabPc::factory()->create(['asset_tag' => 'APU-WHY', 'risk_score' => 72, 'risk_band' => RiskBand::High]);
        RiskAssessment::factory()->forAsset($pc)->score(72)->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.show', ['lab-pc', $pc->id]))
            ->assertOk()
            ->assertSee('APU-WHY')
            ->assertSee('Age')
            ->assertSee('Days since inspection');
    }

    public function test_the_detail_screen_renders_for_an_asset_never_scored(): void
    {
        $pc = LabPc::factory()->create(['risk_score' => null, 'risk_band' => null]);

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.show', ['lab-pc', $pc->id]))
            ->assertOk()
            ->assertSee('never been scored');
    }

    public function test_an_empty_breakdown_is_shown_as_missing_data_not_as_healthy(): void
    {
        /*
         * An access point with no telemetry is usually a dead one. Rendering
         * its 0 as a clean bill of health would be exactly backwards, and the
         * empty factors array is the only thing distinguishing it from a
         * genuine zero.
         */
        $ap = AccessPoint::factory()->create(['risk_score' => 0, 'risk_band' => RiskBand::Low]);
        RiskAssessment::factory()->forAsset($ap)->unscored()->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.show', ['access-point', $ap->id]))
            ->assertOk()
            ->assertSee('Not enough data to score this');
    }

    public function test_the_trend_chart_needs_two_runs_before_it_draws(): void
    {
        $pc = LabPc::factory()->create(['risk_score' => 40, 'risk_band' => RiskBand::Medium]);
        RiskAssessment::factory()->forAsset($pc)->score(40)->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.show', ['lab-pc', $pc->id]))
            ->assertOk()
            ->assertSee('Not enough history to draw a trend');

        RiskAssessment::factory()->forAsset($pc)->score(55)->daysAgo(7)->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.risk.show', ['lab-pc', $pc->id]))
            ->assertOk()
            ->assertDontSee('Not enough history to draw a trend');
    }

    public function test_an_unknown_asset_type_in_the_url_is_not_a_route_to_an_arbitrary_model(): void
    {
        // `assessable_type` holds a fully qualified class name. If the URL
        // segment were used to build one, a crafted request could name any
        // model in the application.
        $this->actingAs($this->engineer())
            ->get('/engineer/risk/user/1')
            ->assertNotFound();
    }

    public function test_the_ranking_is_engineer_furniture(): void
    {
        $this->actingAs($this->assistant())
            ->get(route('engineer.risk.index'))
            ->assertForbidden();
    }

    public function test_a_preventive_ticket_arrives_prefilled_with_the_reasons(): void
    {
        /*
         * FR-311. Preventive, not reactive: every other ticket in the system
         * is raised because something has already broken. This one carries
         * the actual factors, so the engineer is not justifying it from
         * memory.
         */
        $pc = LabPc::factory()->create(['asset_tag' => 'APU-SOON', 'risk_score' => 91, 'risk_band' => RiskBand::Critical]);
        RiskAssessment::factory()->forAsset($pc)->score(91)->create();

        $this->actingAs($this->engineer())
            ->get(route('assistant.tickets.create', ['asset' => 'lab-pc', 'asset_id' => $pc->id]))
            ->assertOk()
            ->assertSee('Preventive maintenance: APU-SOON')
            ->assertSee('Risk score 91')
            ->assertSee('Flagged by the maintenance ranking');
    }

    public function test_a_critical_asset_suggests_a_critical_priority(): void
    {
        // A suggestion, not a decision — the engineer looking at it knows more
        // than the model does.
        $pc = LabPc::factory()->create(['risk_score' => 91, 'risk_band' => RiskBand::Critical]);
        RiskAssessment::factory()->forAsset($pc)->score(91)->create();

        $this->actingAs($this->engineer())
            ->get(route('assistant.tickets.create', ['asset' => 'lab-pc', 'asset_id' => $pc->id]))
            ->assertOk()
            ->assertSee(TicketPriority::Critical->value);
    }

    public function test_an_access_point_can_be_ticketed_this_way_though_not_through_the_normal_form(): void
    {
        /*
         * The assistant form hardcodes lab_pc because an assistant raises
         * faults about machines they are standing at. An engineer acting on a
         * risk ranking is a different situation, and StoreTicketRequest has
         * always accepted access_point.
         */
        $ap = AccessPoint::factory()->create(['ap_code' => 'AP-RISK', 'risk_score' => 80, 'risk_band' => RiskBand::High]);
        RiskAssessment::factory()->forAsset($ap)->score(80)->create();

        $this->actingAs($this->engineer())
            ->get(route('assistant.tickets.create', ['asset' => 'access-point', 'asset_id' => $ap->id]))
            ->assertOk()
            ->assertSee('AP-RISK')
            ->assertSee('access_point', false);
    }

    public function test_a_nonexistent_asset_falls_back_to_a_blank_form(): void
    {
        // Refusing outright helps nobody — the engineer can still raise the
        // ticket by hand.
        $this->actingAs($this->engineer())
            ->get(route('assistant.tickets.create', ['asset' => 'lab-pc', 'asset_id' => 999999]))
            ->assertOk()
            ->assertDontSee('Preventive maintenance');
    }

    public function test_a_risk_score_reads_the_same_from_the_asset_and_the_assessment(): void
    {
        // The ranking reads the cached column and the detail page reads the
        // assessment. They once came back as different types, so a strict
        // comparison between them was false.
        $pc = LabPc::factory()->create();
        $assessment = RiskAssessment::factory()->forAsset($pc)->score(72.5)->create();
        $pc->forceFill(['risk_score' => 72.5])->save();

        $this->assertSame($pc->fresh()->risk_score, $assessment->fresh()->score);
    }
}
