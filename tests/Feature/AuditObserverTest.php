<?php

namespace Tests\Feature;

use App\Enums\ApStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\AuditLog;
use App\Models\ChecklistTemplate;
use App\Models\LabPc;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Shifts\ShiftService;
use App\Services\Telemetry\ReadingData;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the audit log leaves out (FR-509, NFR-06).
 *
 * The audit screen is for people reading what other people changed. Columns
 * the system rewrites as a cache of another table (AUDIT_EXCEPT on the model)
 * are left out, or one collection round per minute writes a row per access
 * point and buries every human action.
 *
 * @see App\Observers\AuditObserver
 */
class AuditObserverTest extends TestCase
{
    use RefreshDatabase;

    private function healthyReading(AccessPoint $ap): ReadingData
    {
        return new ReadingData(
            accessPointId: $ap->id,
            recordedAt: now(),
            latencyMs: 20,
            clientCount: 5,
            packetLossPct: 0.0,
            channelUtilPct: 10.0,
            throughputMbps: 50.0,
            source: 'simulated',
        );
    }

    private function assistant(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        return $user;
    }

    private function updates(): int
    {
        return AuditLog::where('action', 'updated')->count();
    }

    public function test_collection_rounds_that_change_no_status_write_no_audit_rows(): void
    {
        $ap = AccessPoint::factory()->create(['status' => ApStatus::Online]);
        $telemetry = app(TelemetryService::class);

        foreach (range(1, 3) as $round) {
            $this->travel(1)->minutes();
            $telemetry->store(collect([$this->healthyReading($ap)]));
        }

        $this->assertNotNull($ap->fresh()->last_seen_at, 'The heartbeat itself is still written.');
        $this->assertSame(0, $this->updates());
    }

    public function test_a_status_the_collector_sets_is_not_audited(): void
    {
        // Every reading row records the status, and at peak an access point
        // near a threshold flaps every few minutes: auditing each flap wrote
        // hundreds of rows an hour, credited to whoever pressed "Generate
        // sample".
        $ap = AccessPoint::factory()->create(['status' => ApStatus::Offline]);

        app(TelemetryService::class)->store(collect([$this->healthyReading($ap)]));

        $this->assertSame(ApStatus::Online, $ap->fresh()->status);
        $this->assertSame(0, $this->updates());
    }

    public function test_a_status_an_administrator_sets_is_audited(): void
    {
        $ap = AccessPoint::factory()->create(['status' => ApStatus::Online]);

        $ap->update(['status' => ApStatus::Maintenance]);

        $row = AuditLog::forSubject($ap)->where('action', 'updated')->sole();

        $this->assertSame(['status' => 'maintenance'], $row->getAttribute('changes'));
    }

    public function test_a_risk_score_moving_within_its_band_writes_no_row(): void
    {
        $pc = LabPc::factory()->create();

        $pc->forceFill(['risk_score' => 12.5, 'risk_band' => RiskBand::Low])->save();
        $before = $this->updates();

        $pc->forceFill(['risk_score' => 14.25])->save();

        $this->assertSame($before, $this->updates());
    }

    public function test_a_risk_band_change_is_audited_on_its_own(): void
    {
        $pc = LabPc::factory()->create();
        $pc->forceFill(['risk_score' => 12.5, 'risk_band' => RiskBand::Low])->save();

        $pc->forceFill(['risk_score' => 71.0, 'risk_band' => RiskBand::High])->save();

        $row = AuditLog::forSubject($pc)->where('action', 'updated')->latest('id')->first();

        $this->assertSame(['risk_band'], array_keys($row->getAttribute('changes')));
    }

    public function test_an_inspection_stamp_is_not_audited(): void
    {
        // The inspection is the record; the stamp on the machine is a cache.
        $pc = LabPc::factory()->create();
        $before = $this->updates();

        $pc->forceFill(['last_inspected_at' => now()])->save();

        $this->assertSame($before, $this->updates());
    }

    /**
     * Templates are configuration that changes what everybody else's work
     * means: a checklist decides what an inspection asks, a shift pattern who
     * is rostered. "Who changed it, and when" has to be answerable.
     */
    public function test_editing_a_checklist_template_is_audited(): void
    {
        $template = ChecklistTemplate::factory()->create();
        $template->update(['name' => 'Renamed']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ChecklistTemplate::class,
            'auditable_id' => $template->id,
            'action' => 'updated',
        ]);
    }

    public function test_reassigning_a_shift_is_audited_with_both_names(): void
    {
        // Who was given the shift, and who took it off them, is attendance.
        $from = $this->assistant();
        $to = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $from->id]);

        app(ShiftService::class)->reassign($shift, $to);

        $row = AuditLog::forSubject($shift)->where('action', 'updated')->sole();

        $this->assertSame($shift->label(), $row->description);
        $this->assertSame(
            ["user id: {$from->name} → {$to->name}"],
            $row->changeSummary(AuditLog::labelsFor([$row])),
        );
    }

    public function test_removing_a_shift_is_audited(): void
    {
        $shift = Shift::factory()->upcoming()->create(['user_id' => $this->assistant()->id]);

        app(ShiftService::class)->remove($shift);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Shift::class,
            'auditable_id' => $shift->id,
            'action' => 'deleted',
            'description' => $shift->label(),
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
}
