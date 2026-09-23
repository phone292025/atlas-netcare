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
use App\Models\TelemetryHourly;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Reports\ReportingService;
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

    public function test_the_pass_rate_chart_is_labelled_with_codes_and_the_table_with_names(): void
    {
        /*
         * The chart used to take `Location::label()` for its axis, which is
         * "CAF-GF — Campus Cafeteria". Seven of those across a bar chart do
         * not fit, so Chart.js rotated them to 45 degrees where they
         * overlapped each other and swallowed half the plot area — and on a
         * phone they were simply unreadable.
         *
         * The code alone is what goes on the axis. Nothing is lost, because
         * the table beside the chart still carries the full name.
         */
        $location = Location::factory()->create([
            'code' => 'CAF-GF',
            'name' => 'Campus Cafeteria',
        ]);
        $pc = LabPc::factory()->create(['location_id' => $location->id]);

        Inspection::factory()->create([
            'lab_pc_id' => $pc->id, 'user_id' => $this->assistant()->id,
            'overall_result' => InspectionOutcome::Pass, 'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk();

        $this->assertSame(
            ['CAF-GF'],
            $response->viewData('inspectionChart')['labels'],
            'The chart axis is carrying the full location label again.',
        );

        // The table still names the place properly.
        $response->assertSee('Campus Cafeteria');
    }

    public function test_peak_clients_counts_the_whole_room_not_its_busiest_radio(): void
    {
        /*
         * This was MAX(peak_client_count) across a location's access points,
         * which is the busiest moment of ONE radio. In a table headed "by
         * location" it reported 70 for all seven locations on the live
         * database, because every location has an 80-client access point and
         * the simulator drives an 80 to the same peak everywhere. A column
         * that reads identically on every row carries no information.
         *
         * Three access points in one room, peaking at 40, 30 and 25 in the
         * same hour. The old answer is 40. The room held 95.
         */
        $location = Location::factory()->create(['code' => 'BUSY-01']);
        $hour = now()->subDay()->startOfHour();

        foreach ([40, 30, 25] as $peak) {
            $ap = AccessPoint::factory()->create(['location_id' => $location->id]);

            TelemetryHourly::factory()->create([
                'access_point_id' => $ap->id,
                'hour_bucket' => $hour,
                'peak_client_count' => $peak,
            ]);
        }

        $row = collect(app(ReportingService::class)->congestionByLocation())
            ->firstWhere('location', 'BUSY-01');

        $this->assertNotNull($row, 'The location never reached the report.');
        $this->assertSame(3, $row['access_points']);
        $this->assertSame(95, $row['peak_clients'], 'Peak clients is reporting one access point, not the room.');
    }

    public function test_peak_clients_takes_the_busiest_hour_rather_than_adding_hours_together(): void
    {
        // Two hours, one access point. 30 at midday and 10 at midnight is a
        // peak of 30, not 40 — the column is a peak, not a total.
        $location = Location::factory()->create(['code' => 'ONEAP-01']);
        $ap = AccessPoint::factory()->create(['location_id' => $location->id]);

        foreach ([30, 10] as $index => $peak) {
            TelemetryHourly::factory()->create([
                'access_point_id' => $ap->id,
                'hour_bucket' => now()->subDays(2)->startOfHour()->addHours($index),
                'peak_client_count' => $peak,
            ]);
        }

        $row = collect(app(ReportingService::class)->congestionByLocation())
            ->firstWhere('location', 'ONEAP-01');

        $this->assertSame(30, $row['peak_clients']);
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

    public function test_the_ticket_matrix_shows_real_counts_not_a_grid_of_zeroes(): void
    {
        /*
         * The bug this was written against, found by looking at the screen
         * rather than by any test: the heading said "3 tickets" over a matrix
         * where every cell read 0.
         *
         * Eloquent casts `category` and `priority` into enums, so comparing a
         * hydrated model's attribute to a string matches nothing and every
         * lookup silently falls through to its default. toBase() keeps the
         * aggregate rows as plain objects.
         *
         * ASSERT THE NUMBERS, not the headings. The original tests checked
         * that the words "Ticket volume" and "Hardware" appeared, which they
         * did throughout.
         */
        $reporter = $this->assistant();

        Ticket::factory()->count(3)->create([
            'category' => TicketCategory::Hardware,
            'priority' => TicketPriority::Critical,
            'reported_by' => $reporter->id,
        ]);
        Ticket::factory()->create([
            'category' => TicketCategory::Network,
            'priority' => TicketPriority::Low,
            'reported_by' => $reporter->id,
        ]);

        $volume = $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->viewData('ticketVolume');

        $this->assertSame(3, $volume['matrix'][TicketCategory::Hardware->value][TicketPriority::Critical->value]);
        $this->assertSame(3, $volume['matrix'][TicketCategory::Hardware->value]['total']);
        $this->assertSame(1, $volume['matrix'][TicketCategory::Network->value][TicketPriority::Low->value]);
        $this->assertSame(4, $volume['total']);

        // And the row totals must add up to the headline, or the screen is
        // visibly contradicting itself again.
        $this->assertSame(
            $volume['total'],
            collect($volume['matrix'])->sum('total'),
        );
    }

    public function test_a_chart_without_options_still_draws(): void
    {
        /*
         * `new Chart(canvas, { options: [] })` throws "Cannot convert
         * undefined or null to object" and renders a blank box. An empty PHP
         * array serialises to a JS array, not an object.
         *
         * Latent in <x-chart> since M5 — every caller until M7 happened to
         * pass options, so the first chart written without them silently drew
         * nothing. A blank chart looks like missing data, not like a crash,
         * which is why it would have gone unnoticed.
         */
        $html = $this->blade('<x-chart type="bar" :data="[\'labels\' => [\'a\'], \'datasets\' => [[\'data\' => [1]]]]" />');

        $html->assertSee('options: {}', escape: false);
    }

    public function test_a_ticket_resolved_before_it_was_opened_is_excluded_and_said_out_loud(): void
    {
        /*
         * `tickets.opened_at` was a MariaDB `timestamp` until 2026-08-29 and
         * silently rewrote itself to "now" on every status transition, so
         * rows written before that fix can claim to have been resolved
         * eight hours before they were opened. One such row is still in the
         * development database.
         *
         * Averaging a negative in pulls the mean toward zero and makes the
         * SLA look better than it was, so it is excluded. The FIRST version
         * of this dropped it silently, and the screen then said "nothing
         * resolved yet" about a priority that had a resolved ticket — the
         * exact failure this report warns about elsewhere.
         */
        $reporter = $this->assistant();

        Ticket::factory()->create([
            'priority' => TicketPriority::Critical,
            'status' => TicketStatus::Resolved,
            'reported_by' => $reporter->id,
            'opened_at' => now(),
            'resolved_at' => now()->subHours(8),
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk();

        $row = $response->viewData('resolution')[TicketPriority::Critical->value];

        $this->assertSame(1, $row['resolved'], 'The ticket is still counted as resolved.');
        $this->assertSame(0, $row['measurable']);
        $this->assertSame(1, $row['excluded']);
        $this->assertNull($row['mean_hours'], 'A corrupt duration must not reach the mean.');

        // "No usable timestamps" rather than "Nothing resolved yet": the
        // other priorities genuinely have nothing resolved and correctly say
        // so, but this one has a resolved ticket it cannot measure.
        $response->assertSee('1 excluded, resolved before opened')
            ->assertSee('No usable timestamps');
    }

    /*
    |---------------------------------------------------------------------
    | Chart and table shapes, added 2026-09-04
    |---------------------------------------------------------------------
    |
    | These used to be assembled in an `@php` block and four inline
    | `collect()->pluck()` chains inside the Blade file. Nothing queried, so
    | hard rule 2 held, but the view was deciding what the figures contained
    | and none of it could be tested without rendering a page.
    */

    public function test_the_risk_chart_leaves_unscored_assets_out(): void
    {
        /*
         * "Not yet scored" is not a risk band. Charting it beside four that
         * are makes the axis meaningless, and it was the one piece of shaping
         * in the Blade file that had a rule behind it. It stays in the table
         * underneath, where the total has to add up.
         */
        $reports = app(ReportingService::class);

        LabPc::factory()->count(2)->create(['risk_band' => RiskBand::Critical->value]);
        LabPc::factory()->create(['risk_band' => null]);

        $chart = $reports->riskSpreadChart($reports->riskSpread());

        $this->assertNotContains('Not yet scored', $chart['labels']);
        $this->assertCount(count(RiskBand::cases()), $chart['labels']);
        $this->assertCount(count(RiskBand::cases()), $chart['data']);
        $this->assertTrue($chart['anyScored']);
    }

    public function test_the_risk_chart_reports_an_empty_state_when_nothing_is_scored(): void
    {
        // Four zero-height bars is not a chart. This is the flag the view
        // renders an empty state from.
        $reports = app(ReportingService::class);

        LabPc::factory()->count(3)->create(['risk_band' => null]);

        $this->assertFalse($reports->riskSpreadChart($reports->riskSpread())['anyScored']);
    }

    public function test_a_chart_dataset_is_a_list_not_a_keyed_map(): void
    {
        /*
         * The reason every one of these ends in `values()`. `pluck()` on a
         * keyed array keeps the keys, PHP encodes that as a JSON OBJECT rather
         * than an array, and Chart.js silently draws nothing from an object
         * where it expects a list. Nothing on screen and no error.
         */
        $reports = app(ReportingService::class);

        LabPc::factory()->create(['risk_band' => RiskBand::High->value]);

        $chart = $reports->riskSpreadChart($reports->riskSpread());

        $this->assertSame(array_keys($chart['data']), range(0, count($chart['data']) - 1));
        $this->assertStringStartsWith('[', json_encode($chart['data']));
        $this->assertStringStartsWith('[', json_encode($chart['labels']));
    }

    public function test_the_ticket_matrix_headers_name_every_priority(): void
    {
        $reports = app(ReportingService::class);

        $headers = $reports->ticketMatrixHeaders($reports->ticketVolume());

        // Category, one per priority, Total.
        $this->assertCount(count(TicketPriority::cases()) + 2, $headers);
        $this->assertSame('Category', $headers[0]);
        $this->assertSame(['label' => 'Total', 'align' => 'right'], end($headers));
    }

    public function test_the_shaped_figures_reach_the_page(): void
    {
        // The join between the service and the view. A shape the controller
        // does not pass through is a shape nothing renders.
        LabPc::factory()->create(['risk_band' => RiskBand::Medium->value]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk();

        $this->assertIsArray($response->viewData('riskChart'));
        $this->assertIsArray($response->viewData('inspectionChart'));
        $this->assertIsArray($response->viewData('ticketHeaders'));
    }

    public function test_a_pass_with_issues_counts_as_a_pass(): void
    {
        // A machine that works with something cosmetic noted was once reported
        // under "Failed" and dragged its location's pass rate below the truth.
        $location = Location::factory()->create();
        $pc = LabPc::factory()->create(['location_id' => $location->id]);
        $assistant = $this->assistant();

        foreach ([InspectionOutcome::Pass, InspectionOutcome::PassWithIssues] as $outcome) {
            Inspection::factory()->create([
                'lab_pc_id' => $pc->id,
                'user_id' => $assistant->id,
                'overall_result' => $outcome,
                'completed_at' => now(),
            ]);
        }

        $rates = $this->actingAs($this->admin())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->viewData('inspections');

        $this->assertSame(2, $rates[0]['passed']);
        $this->assertSame(0, $rates[0]['failed']);
        $this->assertSame(100.0, $rates[0]['rate']);
    }
}
