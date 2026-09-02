<?php

namespace Tests\Feature;

use App\Enums\ApStatus;
use App\Enums\DeviceStatus;
use App\Enums\InspectionOutcome;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\RiskAssessment;
use App\Models\ShiftTemplate;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Maintenance\InspectionService;
use App\Services\Telemetry\TelemetryService;
use App\Services\Tickets\TicketService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Defects found in the M1–M7 review of 2026-09-02.
 *
 * Each test names the bug it guards rather than the feature it exercises,
 * because every one of these shipped through a suite that was fully green —
 * the code read correctly and did the wrong thing.
 *
 * @see docs/progress/decision-log.md — 2026-09-02
 */
class AuditReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $user;
    }

    // ------------------------------------------------------------------
    // Ticket asset validation
    // ------------------------------------------------------------------

    public function test_a_ticket_cannot_name_an_asset_that_does_not_exist(): void
    {
        /*
         * `ticketable_id` was validated as nothing but an integer. A request
         * naming lab PC 999999 passed, the resolver then returned null, and
         * the ticket was created SILENTLY DETACHED — the reporter believing
         * they had attached a machine, the engineer seeing a blank column.
         */
        $this->actingAs($this->user(UserRole::Assistant))
            ->from(route('assistant.tickets.create'))
            ->post(route('assistant.tickets.store'), [
                'title' => 'Will not boot',
                'description' => 'Nothing on screen.',
                'category' => TicketCategory::Hardware->value,
                'priority' => TicketPriority::Medium->value,
                'ticketable_type' => 'lab_pc',
                'ticketable_id' => 999999,
            ])
            ->assertSessionHasErrors('ticketable_id');

        $this->assertSame(0, Ticket::count());
    }

    public function test_a_ticket_cannot_be_raised_against_a_retired_machine(): void
    {
        // Retired kit is excluded from every list, report and risk score, so a
        // ticket against one sits in the queue attached to something nothing
        // else acknowledges.
        $retired = LabPc::factory()->create(['status' => DeviceStatus::Retired->value]);

        $this->actingAs($this->user(UserRole::Assistant))
            ->from(route('assistant.tickets.create'))
            ->post(route('assistant.tickets.store'), [
                'title' => 'Will not boot',
                'description' => 'Nothing on screen.',
                'category' => TicketCategory::Hardware->value,
                'priority' => TicketPriority::Medium->value,
                'ticketable_type' => 'lab_pc',
                'ticketable_id' => $retired->id,
            ])
            ->assertSessionHasErrors('ticketable_id');
    }

    public function test_a_fault_about_a_place_rather_than_a_machine_still_works(): void
    {
        // The check must not break "no network anywhere on Level 6", which is
        // a real fault with no asset at all.
        $this->actingAs($this->user(UserRole::Assistant))
            ->post(route('assistant.tickets.store'), [
                'title' => 'No network on Level 6',
                'description' => 'Whole floor is down.',
                'category' => TicketCategory::Network->value,
                'priority' => TicketPriority::Critical->value,
                'location_id' => Location::factory()->create()->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Ticket::count());
        $this->assertNull(Ticket::first()->ticketable_id);
    }

    // ------------------------------------------------------------------
    // Retired-machine inspection protection
    // ------------------------------------------------------------------

    public function test_a_retired_machine_cannot_be_inspected(): void
    {
        /*
         * There was no guard at all: an inspection could be started on a
         * machine that was both retired and soft-deleted, producing a record
         * about hardware that is physically gone from the room.
         */
        $pc = LabPc::factory()->create(['status' => DeviceStatus::Retired->value]);
        $pc->delete();

        $this->expectException(DomainException::class);

        app(InspectionService::class)->start($pc, $this->user(UserRole::Assistant));
    }

    public function test_an_operational_machine_can_still_be_inspected(): void
    {
        ChecklistTemplate::factory()->hasItems(3)->create(['is_active' => true]);

        $inspection = app(InspectionService::class)
            ->start(LabPc::factory()->create(), $this->user(UserRole::Assistant));

        $this->assertNotNull($inspection->id);
    }

    // ------------------------------------------------------------------
    // Report aggregation
    // ------------------------------------------------------------------

    public function test_a_pass_with_issues_counts_as_a_pass(): void
    {
        /*
         * The report only ever looked at InspectionOutcome::Pass, so a machine
         * that works with something cosmetic noted was reported in a column
         * headed "Failed" and dragged its location's pass rate below the
         * truth.
         *
         * Two completed, both passes of some kind, so the rate is 100%.
         */
        $location = Location::factory()->create();
        $pc = LabPc::factory()->create(['location_id' => $location->id]);
        $user = $this->user(UserRole::Assistant);

        foreach ([InspectionOutcome::Pass, InspectionOutcome::PassWithIssues] as $outcome) {
            Inspection::factory()->create([
                'lab_pc_id' => $pc->id,
                'user_id' => $user->id,
                'overall_result' => $outcome,
                'completed_at' => now(),
            ]);
        }

        $rates = $this->actingAs($this->user(UserRole::Admin))
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->viewData('inspections');

        $this->assertSame(2, $rates[0]['passed']);
        $this->assertSame(0, $rates[0]['failed']);
        $this->assertSame(100.0, $rates[0]['rate']);
    }

    // ------------------------------------------------------------------
    // Stale access points
    // ------------------------------------------------------------------

    public function test_an_access_point_that_stops_reporting_goes_offline(): void
    {
        /*
         * Every other status change is driven by a reading arriving, including
         * the "silent too long" check — which needs a reading in order to
         * notice the absence of earlier ones. An access point producing NO
         * reading kept its last known status forever, and the dashboard went
         * on calling it online. A real SNMP driver omits a device it cannot
         * enumerate, which is exactly this case.
         */
        $ap = AccessPoint::factory()->create(['status' => ApStatus::Online->value]);
        $ap->forceFill(['last_seen_at' => now()->subHours(3)])->save();

        $changes = app(TelemetryService::class)->markStaleOffline();

        $this->assertCount(1, $changes);
        $this->assertSame(ApStatus::Offline, $ap->fresh()->status);
    }

    public function test_an_access_point_never_heard_from_is_left_alone(): void
    {
        // Newly added kit the collector has not reached yet, not a device that
        // has gone away. Alarming on the minute it was created would teach
        // people to ignore the alarm.
        $ap = AccessPoint::factory()->create(['status' => ApStatus::Online->value]);
        $ap->forceFill(['last_seen_at' => null])->save();

        $this->assertCount(0, app(TelemetryService::class)->markStaleOffline());
        $this->assertSame(ApStatus::Online, $ap->fresh()->status);
    }

    public function test_an_access_point_under_maintenance_is_not_marked_offline(): void
    {
        // Being quiet is what maintenance means.
        $ap = AccessPoint::factory()->maintenance()->create();
        $ap->forceFill(['last_seen_at' => now()->subDay()])->save();

        app(TelemetryService::class)->markStaleOffline();

        $this->assertSame(ApStatus::Maintenance, $ap->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Manual ticket assignment (FR-307)
    // ------------------------------------------------------------------

    public function test_an_engineer_can_hand_a_ticket_to_a_colleague(): void
    {
        $first = $this->user(UserRole::Engineer);
        $second = $this->user(UserRole::Engineer);
        $ticket = Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            'assigned_to' => $first->id,
            'status' => TicketStatus::InProgress,
        ]);

        $this->actingAs($first)
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $second->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($second->id, $ticket->fresh()->assigned_to);
    }

    public function test_assignment_does_not_move_the_ticket_through_its_lifecycle(): void
    {
        // Who is doing the work and how far along it is are different
        // questions. Reassigning mid-repair must not rewind the state machine.
        $ticket = Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            'status' => TicketStatus::InProgress,
        ]);

        app(TicketService::class)->assign($ticket, $this->user(UserRole::Engineer), $this->user(UserRole::Engineer));

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_a_ticket_can_be_put_back_in_the_queue(): void
    {
        // How an engineer going on leave returns work without picking a
        // colleague for it.
        $engineer = $this->user(UserRole::Engineer);
        $ticket = Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            'assigned_to' => $engineer->id,
        ]);

        $this->actingAs($engineer)
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => null])
            ->assertSessionHas('success');

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_work_cannot_be_given_to_a_deactivated_account(): void
    {
        $ticket = Ticket::factory()->create(['reported_by' => $this->user(UserRole::Assistant)->id]);

        $this->expectException(DomainException::class);

        app(TicketService::class)->assign(
            $ticket,
            $this->user(UserRole::Engineer, active: false),
            $this->user(UserRole::Engineer),
        );
    }

    public function test_work_cannot_be_given_to_an_assistant(): void
    {
        $ticket = Ticket::factory()->create(['reported_by' => $this->user(UserRole::Assistant)->id]);

        $this->expectException(DomainException::class);

        app(TicketService::class)->assign($ticket, $this->user(UserRole::Assistant), $this->user(UserRole::Engineer));
    }

    public function test_a_closed_ticket_cannot_be_reassigned(): void
    {
        // Closed is terminal in the state machine, and ownership of finished
        // work is part of its record.
        $ticket = Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            'status' => TicketStatus::Closed,
        ]);

        $this->expectException(DomainException::class);

        app(TicketService::class)->assign($ticket, $this->user(UserRole::Engineer), $this->user(UserRole::Engineer));
    }

    public function test_an_assistant_cannot_assign_tickets(): void
    {
        $ticket = Ticket::factory()->create(['reported_by' => $this->user(UserRole::Assistant)->id]);

        $this->actingAs($this->user(UserRole::Assistant))
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $this->user(UserRole::Engineer)->id])
            ->assertForbidden();
    }

    public function test_assignment_leaves_an_internal_note(): void
    {
        // The trail belongs on the ticket, not only in the audit log — and
        // internal, because the reporter does not need to watch engineers
        // reshuffling work between themselves.
        $engineer = $this->user(UserRole::Engineer);
        $ticket = Ticket::factory()->create(['reported_by' => $this->user(UserRole::Assistant)->id]);

        app(TicketService::class)->assign($ticket, $engineer, $engineer);

        $comment = $ticket->comments()->latest('id')->first();

        $this->assertTrue((bool) $comment->is_internal);
        $this->assertStringContainsString($engineer->name, $comment->body);
    }

    // ------------------------------------------------------------------
    // Auditing, credentials and casting
    // ------------------------------------------------------------------

    public function test_editing_a_checklist_template_is_audited(): void
    {
        /*
         * A checklist template decides what every inspection asks, and a shift
         * template decides who is rostered. Both were administrator-editable
         * with no history at all, so "who changed the checklist, and when" was
         * unanswerable — which matters more here than usual, because M3
         * versions templates precisely so history stays meaningful.
         */
        $template = ChecklistTemplate::factory()->create();
        $template->update(['name' => 'Renamed']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ChecklistTemplate::class,
            'auditable_id' => $template->id,
            'action' => 'updated',
        ]);
    }

    public function test_editing_a_shift_template_is_audited(): void
    {
        $template = ShiftTemplate::factory()->create();
        $template->update(['start_time' => '10:00:00']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ShiftTemplate::class,
            'auditable_id' => $template->id,
            'action' => 'updated',
        ]);
    }

    public function test_the_administrator_password_has_no_published_default(): void
    {
        /*
         * config('atlas.first_admin.password') used to fall back to a value
         * printed in .env.example and therefore in the public repository. A
         * machine with the key unset seeded the highest privilege account in
         * the system with a credential anybody could read, and said nothing.
         */
        config(['atlas.first_admin.password' => null]);

        $this->assertNull(config('atlas.first_admin.password'));
    }

    public function test_the_seeder_refuses_rather_than_using_a_known_password(): void
    {
        config(['atlas.first_admin.password' => null]);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ReferenceSeeder'])
            ->expectsOutputToContain('ATLAS_ADMIN_PASSWORD is not set');

        $this->assertSame(0, User::where('role', UserRole::Admin)->count());
    }

    public function test_a_risk_score_reads_the_same_from_the_asset_and_the_assessment(): void
    {
        /*
         * `risk_assessments.score` was cast to float while the cached columns
         * were decimal:2, so one quantity came back as 46.75 and '46.79' —
         * different types, so any strict comparison between them was false and
         * the two serialised differently in JSON.
         */
        $pc = LabPc::factory()->create();
        $assessment = RiskAssessment::factory()->forAsset($pc)->score(72.5)->create();
        $pc->forceFill(['risk_score' => 72.5])->save();

        $this->assertSame(
            $pc->fresh()->risk_score,
            $assessment->fresh()->score,
            'The cached column and the assessment must agree in value and in type.',
        );
    }
}
