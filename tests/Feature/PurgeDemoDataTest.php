<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Alarm;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\Shift;
use App\Models\TelemetryHourly;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `atlas:purge-demo-data` — turning a demonstration system into a deployable
 * one.
 *
 * Promised in the README, ADR-0003, the data strategy and the database
 * document since Sprint 0, and not written until 2026-09-02.
 *
 * The hard part is not the flagged tables. Only four carry `is_demo`;
 * inspections, shifts, alarms, comments, risk assessments and audit rows have
 * no flag and must be found through whatever they belong to. Six foreign keys
 * are RESTRICT, so getting the order wrong does not corrupt anything — it
 * fails outright, which is the good case. The bad case is the polymorphic
 * `tickets.ticketable`, which has no foreign key at all.
 *
 * @see app/Console/Commands/PurgeDemoData.php
 */
class PurgeDemoDataTest extends TestCase
{
    use RefreshDatabase;

    private function demoAssistant(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true, 'is_demo' => true])->save();

        return $user;
    }

    private function realAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Admin, 'is_active' => true, 'is_demo' => false])->save();

        return $user;
    }

    public function test_it_says_so_when_there_is_nothing_to_purge(): void
    {
        $this->realAdmin();

        $this->artisan('atlas:purge-demo-data', ['--force' => true])
            ->expectsOutputToContain('no demonstration data')
            ->assertExitCode(0);
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->demoAssistant();
        LabPc::factory()->demo()->create();

        $before = LabPc::count();

        $this->artisan('atlas:purge-demo-data', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame($before, LabPc::count());
        $this->assertSame(1, User::where('is_demo', true)->count());
    }

    public function test_it_removes_flagged_rows_and_keeps_real_ones(): void
    {
        $admin = $this->realAdmin();
        $this->demoAssistant();

        $demoPc = LabPc::factory()->demo()->create();
        $realPc = LabPc::factory()->create(['asset_tag' => 'REAL-001']);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, LabPc::where('is_demo', true)->count());
        $this->assertDatabaseHas('lab_pcs', ['id' => $realPc->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('lab_pcs', ['id' => $demoPc->id]);
    }

    public function test_reference_data_survives(): void
    {
        /*
         * Locations and checklists are not demonstration data — they are the
         * system's own vocabulary, and a purge that took them would leave
         * something nobody could use.
         */
        $this->demoAssistant();
        LabPc::factory()->demo()->create();

        $locations = Location::count();

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $this->assertSame($locations, Location::count());
    }

    public function test_rows_with_no_flag_of_their_own_go_by_association(): void
    {
        /*
         * The bulk of the work. An inspection has no `is_demo`, so it has to
         * be found through its lab PC or its assistant — and
         * `inspections.lab_pc_id` is RESTRICT, so missing it does not leave a
         * mess, it makes the delete fail.
         */
        $assistant = $this->demoAssistant();
        $pc = LabPc::factory()->demo()->create();

        $inspection = Inspection::factory()->create([
            'lab_pc_id' => $pc->id,
            'user_id' => $assistant->id,
        ]);

        $shift = Shift::factory()->create(['user_id' => $assistant->id]);
        Alarm::factory()->create(['user_id' => $assistant->id, 'shift_id' => $shift->id]);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('inspections', ['id' => $inspection->id]);
        $this->assertDatabaseMissing('shifts', ['id' => $shift->id]);
        $this->assertSame(0, Alarm::count());
    }

    public function test_simulated_telemetry_goes_even_for_a_real_access_point(): void
    {
        /*
         * The simulator driver writes `source = simulated` whichever access
         * point it is pretending to read, so a real AP can carry invented
         * readings. Those are demonstration data even though the asset is not.
         */
        $realAp = AccessPoint::factory()->create();

        // Distinct hour buckets: telemetry_hourly is uniquely indexed on
        // (network_device_id, hour_bucket), which is the whole point of an
        // hourly rollup.
        foreach (range(1, 3) as $h) {
            TelemetryHourly::factory()->create([
                'network_device_id' => $realAp->id,
                'hour_bucket' => now()->subHours($h)->startOfHour(),
                'source' => 'simulated',
            ]);
        }

        TelemetryHourly::factory()->create([
            'network_device_id' => $realAp->id,
            'hour_bucket' => now()->subHours(9)->startOfHour(),
            'source' => 'live',
        ]);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('network_devices', ['id' => $realAp->id]);
        $this->assertSame(0, TelemetryHourly::where('source', 'simulated')->count());
        $this->assertSame(1, TelemetryHourly::where('source', 'live')->count());
    }

    public function test_it_refuses_to_orphan_a_real_ticket(): void
    {
        /*
         * `tickets.ticketable` is polymorphic and has no foreign key, so
         * nothing at the database level stops a real ticket from being left
         * pointing at a deleted machine — it renders with a blank asset,
         * which looks like a display bug rather than data loss.
         *
         * Not hypothetical: on the machine this was written on, all three
         * genuine tickets pointed at demo lab PCs, because the demo assets
         * were the only machines to raise a ticket against.
         */
        $reporter = $this->realAdmin();
        $demoPc = LabPc::factory()->demo()->create();

        Ticket::factory()->create([
            'ticket_number' => 'TKT-REAL-001',
            'is_demo' => false,
            'reported_by' => $reporter->id,
            'ticketable_type' => LabPc::class,
            'ticketable_id' => $demoPc->id,
        ]);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])
            ->expectsOutputToContain('TKT-REAL-001')
            ->assertExitCode(1);

        // And nothing was deleted on the way to refusing.
        $this->assertDatabaseHas('lab_pcs', ['id' => $demoPc->id]);
    }

    public function test_the_refusal_can_be_overridden_deliberately(): void
    {
        $reporter = $this->realAdmin();
        $demoPc = LabPc::factory()->demo()->create();

        Ticket::factory()->create([
            'is_demo' => false,
            'reported_by' => $reporter->id,
            'ticketable_type' => LabPc::class,
            'ticketable_id' => $demoPc->id,
        ]);

        $this->artisan('atlas:purge-demo-data', ['--force' => true, '--allow-orphans' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('lab_pcs', ['id' => $demoPc->id]);
    }

    public function test_audit_rows_about_demo_activity_go_but_real_history_stays(): void
    {
        $demo = $this->demoAssistant();
        $admin = $this->realAdmin();

        // Written directly: there is no AuditLogFactory, because audit rows
        // are made by an observer rather than by anything a test sets up.
        $row = fn (User $u) => AuditLog::create([
            'user_id' => $u->id,
            'auditable_type' => User::class,
            'auditable_id' => $u->id,
            'action' => 'updated',
            'changes' => ['name' => 'something'],
            'ip_address' => '127.0.0.1',
        ]);

        $row($demo);
        $keep = $row($admin);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, AuditLog::where('user_id', $demo->id)->count());
        $this->assertDatabaseHas('audit_logs', ['id' => $keep->id]);
    }

    public function test_a_retired_demo_machine_is_removed_too(): void
    {
        // lab_pcs soft deletes, and a retired demo machine is still demo data.
        // A purge that only saw the default scope would leave it behind.
        $retired = LabPc::factory()->demo()->create();
        $retired->delete();

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('lab_pcs')->where('id', $retired->id)->count());
    }

    public function test_it_leaves_a_database_a_second_run_finds_empty(): void
    {
        // The check that the association queries actually reached everything:
        // if anything were missed, the second run would find it.
        $this->demoAssistant();
        LabPc::factory()->demo()->count(3)->create();
        AccessPoint::factory()->demo()->count(2)->create();

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])
            ->expectsOutputToContain('no demonstration data')
            ->assertExitCode(0);
    }

    public function test_audit_rows_about_a_demo_ticket_go_even_with_no_actor(): void
    {
        /*
         * Two bugs in one test, both found by purging a fully seeded database
         * rather than a hand-built fixture.
         *
         * FIRST: rows written by a seeder have no `user_id` — nobody is signed
         * in — so matching only the actor left 412 rows describing eighty
         * machines that had just been deleted.
         *
         * SECOND, and the reason this test names a TICKET: the audit step used
         * to run after the tickets were deleted, so its subquery over
         * `tickets WHERE is_demo` matched nothing and 86 rows survived. The
         * step now runs first, while every demo entity still exists.
         */
        $admin = $this->realAdmin();

        $ticket = Ticket::factory()->create([
            'is_demo' => true,
            'reported_by' => $admin->id,
            'ticketable_type' => null,
            'ticketable_id' => null,
        ]);

        $orphanRow = AuditLog::create([
            'user_id' => null,
            'auditable_type' => Ticket::class,
            'auditable_id' => $ticket->id,
            'action' => 'created',
            'changes' => [],
            'ip_address' => null,
        ]);

        $location = Location::factory()->create();

        $keep = AuditLog::create([
            'user_id' => $admin->id,
            'auditable_type' => Location::class,
            'auditable_id' => $location->id,
            'action' => 'created',
            'changes' => [],
            'ip_address' => null,
        ]);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $orphanRow->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $keep->id]);
    }

    public function test_no_audit_row_survives_pointing_at_a_deleted_asset(): void
    {
        // The general form of the same bug: whatever the purge removes, the
        // audit log must not keep describing.
        $this->demoAssistant();
        $pc = LabPc::factory()->demo()->create();

        AuditLog::create([
            'user_id' => null,
            'auditable_type' => LabPc::class,
            'auditable_id' => $pc->id,
            'action' => 'created',
            'changes' => [],
            'ip_address' => null,
        ]);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertExitCode(0);

        $dangling = DB::table('audit_logs')
            ->where('auditable_type', LabPc::class)
            ->whereNotIn('auditable_id', DB::table('lab_pcs')->select('id'))
            ->count();

        $this->assertSame(0, $dangling, 'An audit row still describes a lab PC that no longer exists.');
    }
}
