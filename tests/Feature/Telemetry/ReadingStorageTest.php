<?php

namespace Tests\Feature\Telemetry;

use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Where raw readings live (ADR-0011, roadmap task 1.4).
 *
 * The hypertable checks run only on PostgreSQL, the engine Atlas runs on:
 * SQLite has no TimescaleDB, and CI runs this suite on both.
 */
class ReadingStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_readings_are_a_compressed_hypertable_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('TimescaleDB runs only on PostgreSQL.');
        }

        $hypertable = DB::selectOne(
            "SELECT compression_enabled FROM timescaledb_information.hypertables WHERE hypertable_name = 'telemetry_readings'"
        );

        $this->assertNotNull($hypertable, 'telemetry_readings is not a hypertable.');
        $this->assertTrue((bool) $hypertable->compression_enabled);
    }

    public function test_readings_land_in_one_chunk_per_day_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('TimescaleDB runs only on PostgreSQL.');
        }

        $ap = AccessPoint::factory()->create();

        foreach ([1, 2, 3] as $daysAgo) {
            TelemetryReading::factory()->create(['network_device_id' => $ap->id, 'recorded_at' => now()->subDays($daysAgo)->setTime(12, 0)]);
        }

        $chunks = DB::selectOne("SELECT count(*) AS n FROM show_chunks('telemetry_readings')")->n;

        $this->assertSame(3, (int) $chunks);
    }

    public function test_the_latest_reading_is_the_newest_measured_not_the_last_stored(): void
    {
        // An agent that was offline sends its buffer late, so an older reading
        // can arrive after a newer one. "Latest" means newest by time.
        $ap = AccessPoint::factory()->create();

        TelemetryReading::factory()->create(['network_device_id' => $ap->id, 'recorded_at' => now()->subMinute(), 'client_count' => 7]);
        TelemetryReading::factory()->create(['network_device_id' => $ap->id, 'recorded_at' => now()->subHours(3), 'client_count' => 40]);

        $latest = app(TelemetryService::class)->latestReadings(collect([$ap->id]));

        $this->assertSame(7, $latest->get($ap->id)?->client_count);
    }

    public function test_the_latest_reading_is_found_for_each_device_at_once(): void
    {
        [$a, $b, $silent] = AccessPoint::factory()->count(3)->create();

        TelemetryReading::factory()->create(['network_device_id' => $a->id, 'recorded_at' => now()->subDays(2), 'client_count' => 1]);
        TelemetryReading::factory()->create(['network_device_id' => $a->id, 'recorded_at' => now()->subMinutes(2), 'client_count' => 2]);
        TelemetryReading::factory()->create(['network_device_id' => $b->id, 'recorded_at' => now()->subMinutes(5), 'client_count' => 3]);

        $latest = app(TelemetryService::class)->latestReadings(collect([$a->id, $b->id, $silent->id]));

        $this->assertSame([$a->id => 2, $b->id => 3], $latest->map->client_count->sortKeys()->all());
    }
}
