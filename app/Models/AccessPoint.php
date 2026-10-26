<?php

namespace App\Models;

use App\Enums\DeviceKind;
use App\Enums\RiskBand;
use App\Enums\SimulationScenario;
use Database\Factories\AccessPointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A Wi-Fi access point in one of the monitored zones.
 *
 * A network device of kind `access_point` (ADR-0016), in the same table as
 * switches and routers. This class sees only that kind, and sets it on every
 * access point it creates. What it adds is what only access points have:
 * clients and a capacity, a risk score, and the simulator's scenarios.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 * @see App\Models\NetworkDevice
 */
class AccessPoint extends NetworkDevice
{
    /** @use HasFactory<AccessPointFactory> */
    use HasFactory;

    /**
     * Every new access point is one. 50 clients is the capacity the column
     * defaulted to before it became nullable for other kinds.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'access_point',
        'client_capacity' => 50,
    ];

    /**
     * `risk_score`, `risk_band`, `last_seen_at` and `is_demo` are written by
     * the system — the telemetry collector and M7's scoring run — never by a
     * form. So are `simulation_scenario` and `simulation_started_at`, which
     * only SimulationService sets. `kind` is fixed, so it is not here.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'location_id',
        'parent_id',
        'model',
        'mac_address',
        'ip_address',
        'client_capacity',
        'installed_at',
        'status',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('access_points', function (Builder $query): void {
            $query->where($query->qualifyColumn('kind'), DeviceKind::AccessPoint->value);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'simulation_scenario' => SimulationScenario::class,
            'simulation_started_at' => 'datetime',
            'risk_band' => RiskBand::class,
            'risk_score' => 'decimal:2',
            'client_capacity' => 'integer',
        ];
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
        return $this->hasMany(TelemetryHourly::class, 'access_point_id');
    }
}
