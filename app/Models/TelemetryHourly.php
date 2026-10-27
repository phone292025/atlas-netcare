<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One hour of readings for one network device, summarised.
 *
 * What every chart reads. READ ONLY: `telemetry_hourly` is a TimescaleDB
 * continuous aggregate the database derives from the raw readings (a plain
 * view on SQLite), so a summary can't drift from what was measured. To change
 * an hour, change its readings.
 *
 * @see database/migrations/2026_10_28_090003_derive_hourly_telemetry_from_readings.php
 * @see App\Services\Telemetry\TelemetryStorage
 */
class TelemetryHourly extends Model
{
    protected $table = 'telemetry_hourly';

    /** A view: no key, and no timestamps of its own. */
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hour_bucket' => 'datetime',
            'avg_latency_ms' => 'decimal:2',
            'peak_client_count' => 'integer',
            'avg_packet_loss_pct' => 'decimal:2',
            'avg_channel_util_pct' => 'decimal:2',
            'uptime_pct' => 'decimal:2',
            'reading_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException(
            'telemetry_hourly is derived from telemetry_readings. Write readings instead.'
        ));
    }

    /** @return BelongsTo<NetworkDevice, $this> */
    public function device(): BelongsTo
    {
        // withTrashed: removing an access point keeps its telemetry, and the
        // traffic log still lists those hours. Without this they had no name.
        return $this->belongsTo(NetworkDevice::class, 'network_device_id')->withTrashed();
    }

    /**
     * Only access points' hours: the traffic screens are about clients and
     * throughput, which other kinds don't report.
     *
     * @param  Builder<TelemetryHourly>  $query
     */
    public function scopeAccessPoints(Builder $query): void
    {
        $query->whereIn('network_device_id', AccessPoint::withTrashed()->select('id'));
    }
}
