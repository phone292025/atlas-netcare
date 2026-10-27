<?php

namespace Tests\Feature\Telemetry;

use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The engineer's dashboard: access points by room, and a reading over the
 * limits called slow or busy even while the status still says Online.
 *
 * The status changes only after `consecutive_breach` readings in a row, so
 * one noisy sample cannot raise an alarm. Until then the latest reading can
 * already be over the limits, and a screen that showed its red number next
 * to a green "Online" contradicted itself. `over_threshold` and `breach` let
 * the screen say "Slow now" or "Busy now" instead.
 */
class DashboardHealthTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, mixed>> keyed by access point code */
    private function payload(): array
    {
        return collect(app(TelemetryService::class)->dashboardData()['access_points'])->keyBy('code')->all();
    }

    public function test_a_reading_over_the_limits_is_flagged_while_the_status_is_still_online(): void
    {
        $slow = AccessPoint::factory()->create(['code' => 'AP-01', 'client_capacity' => 80]);
        $fine = AccessPoint::factory()->create(['code' => 'AP-02', 'client_capacity' => 80]);

        TelemetryReading::factory()->slow()->create(['network_device_id' => $slow->id, 'recorded_at' => now()->subMinute(), 'client_count' => 4]);
        TelemetryReading::factory()->create(['network_device_id' => $fine->id, 'recorded_at' => now()->subMinute(), 'latency_ms' => 30, 'client_count' => 4]);

        $aps = $this->payload();

        $this->assertSame('online', $aps['AP-01']['status']);
        $this->assertTrue($aps['AP-01']['over_threshold']);
        $this->assertFalse($aps['AP-02']['over_threshold']);
    }

    public function test_the_reason_is_slow_for_latency_and_busy_for_a_nearly_full_access_point(): void
    {
        // Saturation counts as over the limits too, but a full access point
        // is not a slow one. Calling it "slow" sent people after the wrong fault.
        $slow = AccessPoint::factory()->create(['code' => 'AP-01', 'client_capacity' => 80]);
        $busy = AccessPoint::factory()->create(['code' => 'AP-02', 'client_capacity' => 30]);

        TelemetryReading::factory()->slow()->create(['network_device_id' => $slow->id, 'recorded_at' => now()->subMinute(), 'client_count' => 4]);
        TelemetryReading::factory()->create(['network_device_id' => $busy->id, 'recorded_at' => now()->subMinute(), 'latency_ms' => 30, 'client_count' => 28]);

        $aps = $this->payload();

        $this->assertSame('slow', $aps['AP-01']['breach']);
        $this->assertSame('busy', $aps['AP-02']['breach']);
        $this->assertTrue($aps['AP-02']['over_threshold']);
    }

    public function test_an_access_point_with_no_reading_is_not_called_slow(): void
    {
        AccessPoint::factory()->create(['code' => 'AP-01']);

        $this->assertFalse($this->payload()['AP-01']['over_threshold']);
    }

    public function test_each_access_point_carries_its_room_for_grouping(): void
    {
        $ap = AccessPoint::factory()->create(['code' => 'AP-01']);

        $this->assertSame($ap->location->name, $this->payload()['AP-01']['location_name']);
    }

    public function test_your_queue_shows_each_tickets_time_against_its_target(): void
    {
        $engineer = User::factory()->create();
        $engineer->forceFill(['role' => UserRole::Engineer, 'is_active' => true])->save();
        Ticket::factory()->breaching()->assignedTo($engineer)->create();

        $this->actingAs($engineer)
            ->get(route('engineer.dashboard'))
            ->assertOk()
            ->assertSee('d past');
    }
}
