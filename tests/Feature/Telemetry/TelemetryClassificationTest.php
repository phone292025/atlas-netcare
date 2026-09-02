<?php

namespace Tests\Feature\Telemetry;

use App\Enums\ApStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use App\Services\Telemetry\ReadingData;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M5 — turning numbers into a health state (Q1).
 *
 * This is the rule the dashboard colours come from and the rule that decides
 * whether a ticket gets raised at 3am, so it is tested against controlled
 * readings rather than against whatever the simulator happens to produce.
 *
 * @see App\Services\Telemetry\TelemetryService::classify()
 * @see docs/02-open-questions.md Q1
 */
class TelemetryClassificationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TelemetryService
    {
        return app(TelemetryService::class);
    }

    /** A healthy reading for an access point with plenty of headroom. */
    private function reading(AccessPoint $ap, array $overrides = []): ReadingData
    {
        return new ReadingData(
            accessPointId: $ap->id,
            recordedAt: $overrides['recordedAt'] ?? now(),
            latencyMs: $overrides['latencyMs'] ?? 20,
            clientCount: $overrides['clientCount'] ?? 5,
            packetLossPct: $overrides['packetLossPct'] ?? 0.1,
            channelUtilPct: 30,
            throughputMbps: 40,
            source: 'simulated',
            unreachable: $overrides['unreachable'] ?? false,
        );
    }

    /** n previous readings that all breach, so the next one can tip it over. */
    private function priorBreaches(AccessPoint $ap, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            TelemetryReading::factory()->slow()->create([
                'access_point_id' => $ap->id,
                'recorded_at' => now()->subMinutes($i),
            ]);
        }
    }

    // ------------------------------------------------------------ healthy

    public function test_a_good_reading_is_online(): void
    {
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);

        $this->assertSame(
            ApStatus::Online,
            $this->service()->classify($ap, $this->reading($ap))
        );
    }

    // ------------------------------------------------------------ offline

    public function test_an_unreachable_access_point_is_offline(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->assertSame(
            ApStatus::Offline,
            $this->service()->classify($ap, $this->reading($ap, ['unreachable' => true]))
        );
    }

    public function test_an_access_point_silent_past_the_window_is_offline(): void
    {
        /*
         * Covers the case a failed poll does not: the collector itself stopped
         * running. Without this an access point nobody has heard from since
         * Tuesday still shows online, because no reading ever arrived to say
         * otherwise.
         */
        $ap = AccessPoint::factory()->create([
            'last_seen_at' => now()->subMinutes(30),
        ]);

        $this->assertSame(
            ApStatus::Offline,
            $this->service()->classify($ap, $this->reading($ap))
        );
    }

    public function test_a_recently_seen_access_point_is_not_offline(): void
    {
        $ap = AccessPoint::factory()->create([
            'client_capacity' => 50,
            'last_seen_at' => now()->subMinute(),
        ]);

        $this->assertSame(
            ApStatus::Online,
            $this->service()->classify($ap, $this->reading($ap))
        );
    }

    // ------------------------------------------------- the consecutive rule

    public function test_a_single_bad_reading_does_not_degrade_an_access_point(): void
    {
        /*
         * The whole reason `consecutive_breach` exists. One noisy sample must
         * not flip the dashboard colour and auto-raise a ticket — that is how
         * people learn to ignore the dashboard.
         */
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);

        $this->assertSame(
            ApStatus::Online,
            $this->service()->classify($ap, $this->reading($ap, ['latencyMs' => 400]))
        );
    }

    public function test_a_sustained_breach_degrades_an_access_point(): void
    {
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);

        // Two previous breaches plus this one makes three.
        $this->priorBreaches($ap, 2);

        $this->assertSame(
            ApStatus::Degraded,
            $this->service()->classify($ap, $this->reading($ap, ['latencyMs' => 400]))
        );
    }

    public function test_one_good_reading_in_the_run_keeps_it_online(): void
    {
        // The run has to be unbroken. A recovery in the middle means the
        // access point is flapping, not degraded.
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);

        TelemetryReading::factory()->slow()->create([
            'access_point_id' => $ap->id,
            'recorded_at' => now()->subMinutes(2),
        ]);
        TelemetryReading::factory()->create([
            'access_point_id' => $ap->id,
            'recorded_at' => now()->subMinute(),
        ]);

        $this->assertSame(
            ApStatus::Online,
            $this->service()->classify($ap, $this->reading($ap, ['latencyMs' => 400]))
        );
    }

    public function test_a_brand_new_access_point_is_not_degraded_on_its_first_reading(): void
    {
        // Not enough history to be sure. Online is the safer wrong answer.
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);

        $this->assertSame(
            ApStatus::Online,
            $this->service()->classify($ap, $this->reading($ap, ['latencyMs' => 400]))
        );
    }

    // ------------------------------------------------- each threshold alone

    public function test_packet_loss_alone_can_degrade_an_access_point(): void
    {
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);

        for ($i = 1; $i <= 2; $i++) {
            TelemetryReading::factory()->lossy()->create([
                'access_point_id' => $ap->id,
                'recorded_at' => now()->subMinutes($i),
            ]);
        }

        $this->assertSame(
            ApStatus::Degraded,
            $this->service()->classify($ap, $this->reading($ap, ['packetLossPct' => 22.5]))
        );
    }

    public function test_client_saturation_alone_can_degrade_an_access_point(): void
    {
        // 48 of 50 is 96%, over the 85% threshold, with latency and loss fine.
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);

        for ($i = 1; $i <= 2; $i++) {
            TelemetryReading::factory()->create([
                'access_point_id' => $ap->id,
                'recorded_at' => now()->subMinutes($i),
                'client_count' => 48,
            ]);
        }

        $this->assertSame(
            ApStatus::Degraded,
            $this->service()->classify($ap, $this->reading($ap, ['clientCount' => 48]))
        );
    }

    // ------------------------------------------------------- maintenance

    public function test_maintenance_survives_classification(): void
    {
        /*
         * An engineer sets Maintenance to stop a planned outage paging
         * anybody. It is an administrative decision, not a measurement, so a
         * reading must not overwrite it — otherwise the suppression lasts
         * exactly until the next poll.
         */
        $ap = AccessPoint::factory()->maintenance()->create();

        $this->assertSame(
            ApStatus::Maintenance,
            $this->service()->classify($ap, $this->reading($ap, ['unreachable' => true]))
        );
    }

    public function test_an_access_point_under_maintenance_is_not_polled(): void
    {
        AccessPoint::factory()->maintenance()->create();
        AccessPoint::factory()->create();

        $this->assertSame(1, AccessPoint::query()->collectable()->count());
    }
}
