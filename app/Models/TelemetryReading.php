<?php

namespace App\Models;

use Database\Factories\TelemetryReadingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One measurement of one network device.
 *
 * NO TIMESTAMPS. `recorded_at` is the only time that means anything here, and
 * this is the one table where two extra datetimes per row is a real cost —
 * roughly 1.7 million rows a month.
 *
 * `status` is a plain string, NOT cast to NetworkStatus. A reading records a
 * MEASURED state and only ever holds online / degraded / offline; NetworkStatus is
 * wider because it also carries `maintenance`, which is an administrative
 * decision about the access point rather than something anyone measured.
 * Casting here would let a reading claim a device is under maintenance.
 *
 * @see docs/04-database.md
 * @see docs/progress/decision-log.md — 2026-08-27
 */
class TelemetryReading extends Model
{
    /** @use HasFactory<TelemetryReadingFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'network_device_id',
        'recorded_at',
        'latency_ms',
        'client_count',
        'packet_loss_pct',
        'channel_util_pct',
        'throughput_mbps',
        'status',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'latency_ms' => 'integer',
            'client_count' => 'integer',
            'packet_loss_pct' => 'decimal:2',
            'channel_util_pct' => 'decimal:2',
            'throughput_mbps' => 'decimal:2',
        ];
    }

    /**
     * The device measured: an access point, or from roadmap task 1.3 a
     * switch or router. An access point loads as an AccessPoint.
     *
     * @return BelongsTo<NetworkDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id');
    }

    /** @param  Builder<TelemetryReading>  $query */
    public function scopeSimulated(Builder $query): void
    {
        $query->where('source', 'simulated');
    }
}
