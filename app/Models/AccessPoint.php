<?php

namespace App\Models;

use App\Enums\ApStatus;
use App\Enums\RiskBand;
use Database\Factories\AccessPointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Wi-Fi access point in one of the monitored zones.
 *
 * `status` here is the ADMINISTRATIVE state of the device and may be
 * `maintenance` — an engineer sets that to suppress alarms during planned
 * work. It is a wider set than telemetry_readings.status, which records a
 * MEASURED state and only ever holds online / degraded / offline. M5 must not
 * cast that column to ApStatus.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 * @see docs/progress/decision-log.md — 2026-08-27
 */
class AccessPoint extends Model
{
    /** @use HasFactory<AccessPointFactory> */
    use HasFactory, SoftDeletes;

    /**
     * `risk_score`, `risk_band`, `last_seen_at` and `is_demo` are written by
     * the system — the telemetry collector and M7's scoring run — never by a
     * form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ap_code',
        'location_id',
        'model',
        'mac_address',
        'ip_address',
        'client_capacity',
        'installed_at',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ApStatus::class,
            'risk_band' => RiskBand::class,
            'installed_at' => 'date',
            'last_seen_at' => 'datetime',
            'risk_score' => 'decimal:2',
            'client_capacity' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Access points the collector should poll.
     *
     * An access point under planned maintenance is skipped, so it does not
     * generate offline alarms while somebody is standing on a ladder holding
     * it.
     *
     * @param  Builder<AccessPoint>  $query
     */
    public function scopeCollectable(Builder $query): void
    {
        $query->where('status', '!=', ApStatus::Maintenance);
    }

    /** @param  Builder<AccessPoint>  $query */
    public function scopeDemo(Builder $query): void
    {
        $query->where('is_demo', true);
    }

    /**
     * How full this access point is, as a 0..1 ratio.
     *
     * One of the three degradation thresholds in config('atlas.thresholds').
     * Returns null rather than dividing by zero when capacity is unset.
     */
    public function saturation(int $clientCount): ?float
    {
        return $this->client_capacity > 0
            ? $clientCount / $this->client_capacity
            : null;
    }
}
