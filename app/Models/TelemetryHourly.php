<?php

namespace App\Models;

use Database\Factories\TelemetryHourlyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hour of readings for one access point, rolled up.
 *
 * What every chart reads. See the migration for why.
 *
 * @see docs/06-database.md
 */
class TelemetryHourly extends Model
{
    /** @use HasFactory<TelemetryHourlyFactory> */
    use HasFactory;

    protected $table = 'telemetry_hourly';

    /** @var list<string> */
    protected $fillable = [
        'access_point_id',
        'hour_bucket',
        'avg_latency_ms',
        'peak_client_count',
        'avg_packet_loss_pct',
        'avg_channel_util_pct',
        'uptime_pct',
        'reading_count',
        'source',
    ];

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

    /** @return BelongsTo<AccessPoint, $this> */
    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }
}
