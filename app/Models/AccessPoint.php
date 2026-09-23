<?php

namespace App\Models;

use App\Enums\ApStatus;
use App\Enums\RiskBand;
use App\Enums\SimulationScenario;
use Database\Factories\AccessPointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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
     * Columns the audit log skips: caches whose history is kept elsewhere
     * (telemetry_readings, risk_assessments). Auditing `last_seen_at` wrote
     * one row per access point per minute. The collector also saves `status`
     * quietly, since every reading records it; a status an administrator sets
     * and a risk band change are still audited.
     *
     * @see App\Observers\AuditObserver
     * @see App\Services\Telemetry\TelemetryService::store()
     */
    public const AUDIT_EXCEPT = ['last_seen_at', 'risk_score'];

    /**
     * `risk_score`, `risk_band`, `last_seen_at` and `is_demo` are written by
     * the system — the telemetry collector and M7's scoring run — never by a
     * form. So are `simulation_scenario` and `simulation_started_at`, which
     * only SimulationService sets.
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
            'simulation_scenario' => SimulationScenario::class,
            'simulation_started_at' => 'datetime',
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

    /**
     * Faults raised against this asset.
     *
     * Polymorphic because `tickets.ticketable` covers both asset types, so
     * M7's fault-frequency factor reads the same relation whichever it is
     * scoring.
     *
     * @return MorphMany<Ticket, $this>
     */
    public function tickets(): MorphMany
    {
        return $this->morphMany(Ticket::class, 'ticketable');
    }

    /**
     * Every risk score this asset has ever been given, for the trend chart.
     *
     * @return MorphMany<RiskAssessment, $this>
     */
    public function riskAssessments(): MorphMany
    {
        return $this->morphMany(RiskAssessment::class, 'assessable');
    }

    /**
     * The most recent score, with its factors.
     *
     * The cached `risk_score` column carries the same number for lists; this
     * is for a detail page, which needs the reasons as well.
     *
     * @return MorphOne<RiskAssessment, $this>
     */
    public function latestRiskAssessment(): MorphOne
    {
        return $this->morphOne(RiskAssessment::class, 'assessable')->latestOfMany('calculated_at');
    }

    /**
     * Hourly telemetry rollups, which are what M7 measures trends on.
     *
     * Trends read the aggregates rather than raw readings deliberately: two
     * weeks of per-minute rows is around twenty thousand per access point,
     * and an hourly mean is the right resolution for "is this link getting
     * worse" anyway. A single bad minute is weather.
     *
     * @return HasMany<TelemetryHourly, $this>
     */
    public function hourly(): HasMany
    {
        return $this->hasMany(TelemetryHourly::class);
    }
}
