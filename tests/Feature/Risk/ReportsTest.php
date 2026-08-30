<?php

namespace Tests\Feature\Risk;

use App\Enums\InspectionOutcome;
use App\Enums\RiskBand;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The five operational reports (FR-410).
 *
 * Every test loads the page. A report screen that has only ever been asserted
 * to redirect has never had its aggregation run, and aggregation is where the
 * SQLite-versus-MariaDB differences bite.
 *
 * @see docs/modules/M7-predictive-and-reports.md
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        return $user;
    }

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

    public function test_every_report_renders_on_an_empty_system(): void
    {
        /*
         * The state on the day it ships, and the one that breaks aggregation:
         * dividing by a zero count, averaging an empty set, charting an empty
         * label array. Each report has to say it has nothing rather than 500.
         */
        $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Nothing has been scored yet')
            ->assertSee('No tickets yet')
            ->assertSee('No completed inspections yet')
            ->assertSee('No telemetry in the last thirty days');
    }

    public function test_the_risk_spread_counts_both_asset_types_and_names_the_unscored(): void
    {
        LabPc::factory()->count(2)->create(['risk_band' => RiskBand::Critical, 'risk_score' => 90]);
        AccessPoint::factory()->create(['risk_band' => RiskBand::Critical, 'risk_score' => 88]);

        // Never scored is reported, not hidden. A report that silently drops
        // rows is how a total stops adding up.
        LabPc::factory()->create(['risk_band' => null, 'risk_score' => null]);

        $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Critical Risk')
            ->assertSee('Not yet scored');
    }

    public function test_mean_resolution_time_is_computed_without_vendor_specific_sql(): void
    {
        /*
         * The trap this whole controller is written around. MySQL wants
         * TIMESTAMPDIFF and SQLite wants julianday, and a query written for
         * one returns nonsense on the other rather than failing — which is
         * exactly how the FIELD() and DATE_FORMAT defects reached production
         * through a green suite.
         *
         * Two critical tickets, resolved after 2 and 4 hours. The mean is 3.
         */
        $reporter = $this->assistant();

        foreach ([2, 4] as $hours) {
            Ticket::factory()->create([
                'priority' => TicketPriority::Critical,
                'status' => TicketStatus::Resolved,
                'reported_by' => $reporter->id,
                'opened_at' => now()->subHours($hours + 1),
                'resolved_at' => now()->subHour(),
            ]);
        }

        $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('3.0');
    }

    public function test_unresolved_tickets_are_excluded_rather_than_counted_as_zero(): void
    {
        // Counting an open ticket as zero hours would drag every mean toward
        // zero and make the SLA look comfortably met while nothing was fixed.
        $reporter = $this->assistant();

        Ticket::factory()->create([
            'priority' => TicketPriority::Low,
            'status' => TicketStatus::Open,
            'reported_by' => $reporter->id,
            'opened_at' => now()->subDays(5),
            'resolved_at' => null,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Nothing resolved yet');
    }

    public function test_inspection_pass_rate_ignores_inspections_nobody_finished(): void
    {
        /*
         * An abandoned inspection has no outcome. Counting it as a failure
         * would blame a room for an assistant's interrupted shift.
         *
         * Two completed here, one passed, so the rate is 50%. The third is
         * started and never finished and must not move that number.
         */
        $location = Location::factory()->create();
        $pc = LabPc::factory()->create(['location_id' => $location->id]);
        $user = $this->assistant();

        Inspection::factory()->create([
            'lab_pc_id' => $pc->id, 'user_id' => $user->id,
            'overall_result' => InspectionOutcome::Pass, 'completed_at' => now(),
        ]);
        Inspection::factory()->create([
            'lab_pc_id' => $pc->id, 'user_id' => $user->id,
            'overall_result' => InspectionOutcome::Fail, 'completed_at' => now(),
        ]);
        Inspection::factory()->create([
            'lab_pc_id' => $pc->id, 'user_id' => $user->id,
            'overall_result' => null, 'completed_at' => null,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('50%');
    }

    public function test_ticket_volume_is_a_matrix_not_two_separate_counts(): void
    {
        // "How many hardware faults" and "how many were critical" are each
        // half an answer.
        $reporter = $this->assistant();

        Ticket::factory()->count(2)->create([
            'category' => TicketCategory::Hardware,
            'priority' => TicketPriority::Critical,
            'reported_by' => $reporter->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Ticket volume')
            ->assertSee(TicketCategory::Hardware->label());
    }

    public function test_an_engineer_can_read_the_reports(): void
    {
        // Named admin.reports.index for fixed navigation vocabulary, but mean
        // time to resolution is an engineer's own number.
        $this->actingAs($this->engineer())
            ->get(route('admin.reports.index'))
            ->assertOk();
    }

    public function test_an_assistant_cannot(): void
    {
        $this->actingAs($this->assistant())
            ->get(route('admin.reports.index'))
            ->assertForbidden();
    }
}
