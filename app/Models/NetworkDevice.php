<?php

namespace App\Models;

use App\Enums\DeviceKind;
use App\Enums\NetworkStatus;
use Database\Factories\NetworkDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anything on the network Atlas watches: an access point, a switch, a router.
 *
 * One table for every kind (ADR-0016), because what makes a device watchable
 * is the same for all of them: a status, when it was last heard from, its
 * readings, and the device it hangs from. An access point is a row of kind
 * `access_point`, and a row of that kind always loads as an AccessPoint, which
 * adds what only access points have.
 *
 * `status` is the ADMINISTRATIVE state and may be `maintenance`, which an
 * engineer sets to suppress alarms during planned work. It is a wider set than
 * telemetry_readings.status, which records a MEASURED state.
 *
 * @see docs/04-database.md
 */
class NetworkDevice extends Model
{
    /** @use HasFactory<NetworkDeviceFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'network_devices';

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
    public const AUDIT_EXCEPT = ['last_seen_at', 'last_reading_at', 'risk_score'];

    /**
     * `last_seen_at` and `is_demo` are written by the system, never by a form.
     * `kind` is fillable here because the network devices form picks it, and
     * its request refuses `access_point`: access points are added through
     * their own form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'code',
        'name',
        'location_id',
        'parent_id',
        'agent_id',
        'model',
        'mac_address',
        'ip_address',
        'installed_at',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DeviceKind::class,
            'status' => NetworkStatus::class,
            'installed_at' => 'date',
            'last_seen_at' => 'datetime',
            'last_reading_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    /**
     * Rows of kind `access_point` load as AccessPoint, from any query.
     *
     * Without this, a device found through its parent, or a ticket's
     * `ticketable`, would be a plain NetworkDevice with no capacity, no
     * saturation and no risk score.
     *
     * @param  array<string, mixed>|object  $attributes
     * @param  string|null  $connection
     */
    public function newFromBuilder($attributes = [], $connection = null): static
    {
        $attributes = (array) $attributes;

        // A query through AccessPoint only ever finds access points, so it
        // needs no `kind`, which a query selecting a few columns leaves out.
        if (static::class !== self::class || ($attributes['kind'] ?? null) !== DeviceKind::AccessPoint->value) {
            return parent::newFromBuilder($attributes, $connection);
        }

        $model = (new AccessPoint)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());
        $model->fireModelEvent('retrieved', false);

        return $model;
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The device this one hangs from, such as the switch an access point
     * plugs into. When both fail together, the fault is the parent's.
     *
     * @return BelongsTo<NetworkDevice, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'parent_id');
    }

    /**
     * The probe agent that measures this device. Only it may report readings
     * for it (ADR-0017).
     *
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return HasMany<NetworkDevice, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(NetworkDevice::class, 'parent_id');
    }

    /**
     * Faults raised against this device. Polymorphic, because a ticket can be
     * about a lab PC as well.
     *
     * @return MorphMany<Ticket, $this>
     */
    public function tickets(): MorphMany
    {
        return $this->morphMany(Ticket::class, 'ticketable');
    }

    /**
     * Devices the collector should poll. One under planned maintenance is
     * skipped, so it doesn't raise offline alarms while somebody is standing
     * on a ladder holding it.
     *
     * @param  Builder<static>  $query
     */
    public function scopeCollectable(Builder $query): void
    {
        $query->where('status', '!=', NetworkStatus::Maintenance);
    }

    /** @param  Builder<static>  $query */
    public function scopeDemo(Builder $query): void
    {
        $query->where('is_demo', true);
    }

    /** "SW-CORE · Core switch", or the code alone when it has no name. */
    public function label(): string
    {
        return $this->name ? "{$this->code} · {$this->name}" : $this->code;
    }

    /**
     * What a device can be connected to, for a select: every switch, router,
     * server and other device still in the inventory, but not `$device` itself
     * or anything below it, which would close a loop.
     *
     * @return array<int, string> id => "SW-CORE · Core switch", or "SW-02 (Switch)" with no name
     */
    public static function parentChoices(?NetworkDevice $device = null): array
    {
        $candidates = self::query()
            ->where('kind', '!=', DeviceKind::AccessPoint->value)
            ->orderBy('code')
            ->get(['id', 'kind', 'code', 'name', 'parent_id']);

        $excluded = [];

        if ($device?->exists) {
            // Breadth first, down from the device. In memory: a campus has
            // tens of these, and a query per level would be one per row.
            $excluded = [$device->getKey() => true];
            $queue = [$device->getKey()];

            while ($queue) {
                $id = array_shift($queue);

                foreach ($candidates->where('parent_id', $id) as $child) {
                    if (! isset($excluded[$child->getKey()])) {
                        $excluded[$child->getKey()] = true;
                        $queue[] = $child->getKey();
                    }
                }
            }
        }

        return $candidates
            ->reject(fn (NetworkDevice $candidate) => isset($excluded[$candidate->getKey()]))
            ->mapWithKeys(fn (NetworkDevice $candidate) => [$candidate->getKey() => $candidate->name
                ? $candidate->label()
                : "{$candidate->code} ({$candidate->kind->label()})"])
            ->all();
    }

    /**
     * Whether `$candidate` is this device or hangs below it, at any depth.
     * Making either the parent would close a loop, which the grouping of
     * faults by parent could never leave.
     */
    public function isSelfOrAncestorOf(NetworkDevice $candidate): bool
    {
        $seen = [];

        for ($node = $candidate; $node !== null; $node = $node->parent) {
            if ($node->is($this)) {
                return true;
            }

            // A loop already in the data must not hang the request.
            if (isset($seen[$node->getKey()])) {
                return false;
            }

            $seen[$node->getKey()] = true;
        }

        return false;
    }
}
