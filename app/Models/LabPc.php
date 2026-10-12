<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Enums\RiskBand;
use Database\Factories\LabPcFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A university-owned lab workstation.
 *
 * Q7: a lab PC never reports its own health. No agent is installed on campus
 * machines, so everything this record knows arrives through an assistant
 * completing a checklist or raising a ticket. That is why M7 scores these from
 * inspection history, ticket history and age rather than live telemetry.
 *
 * @see docs/modules/M2-locations-and-assets.md
 * @see docs/02-open-questions.md Q7
 */
class LabPc extends Model
{
    /** @use HasFactory<LabPcFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Columns the audit log skips: caches whose history is kept elsewhere
     * (inspections, risk_assessments). Risk band changes are still recorded.
     *
     * @see App\Observers\AuditObserver
     */
    public const AUDIT_EXCEPT = ['last_inspected_at', 'risk_score'];

    /**
     * `risk_score`, `risk_band`, `last_inspected_at` and `is_demo` are absent
     * on purpose. They are written by the system — M7's scoring run, M3's
     * inspection service, the demo seeder — never by a form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'asset_tag',
        'location_id',
        'hostname',
        'cpu',
        'ram_gb',
        'storage',
        'os_version',
        'purchase_date',
        'warranty_expiry',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'risk_band' => RiskBand::class,
            'purchase_date' => 'date',
            'warranty_expiry' => 'date',
            'last_inspected_at' => 'datetime',
            'risk_score' => 'decimal:2',
            'ram_gb' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Every time somebody checked this machine.
     *
     * Added by M3. It is the machine's whole health record — Q7 says a lab PC
     * never reports on itself — so M7 scores from this and the lab PC show
     * page will list it.
     *
     * @return HasMany<Inspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    /**
     * Machines still in service.
     *
     * Retired machines keep their history but drop out of inspection rounds
     * and risk scoring.
     *
     * @param  Builder<LabPc>  $query
     */
    public function scopeInService(Builder $query): void
    {
        $query->where('status', '!=', DeviceStatus::Retired);
    }

    /** @param  Builder<LabPc>  $query */
    public function scopeDemo(Builder $query): void
    {
        $query->where('is_demo', true);
    }

    /**
     * Years since purchase, for M7's age risk factor.
     *
     * Null when the purchase date is unknown, so the scorer can skip the
     * factor rather than treat a missing date as a brand-new machine.
     */
    public function ageInYears(): ?float
    {
        return $this->purchase_date?->diffInYears(now());
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
}
