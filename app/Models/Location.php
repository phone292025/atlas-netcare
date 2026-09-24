<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A campus place that houses assets: a lab, the library, the cafeteria.
 *
 * REFERENCE DATA, not demo data. Locations are seeded by ReferenceSeeder and
 * never tagged is_demo, because a real deployment needs them on day one and
 * `atlas:purge-demo-data` must not take them away.
 *
 * No soft deletes: `restrictOnDelete` on the child tables means a location
 * holding assets cannot be removed at all, which is the behaviour we want.
 *
 * @see docs/modules/M2-locations-and-assets.md
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'floor',
        'description',
    ];

    /** @return HasMany<LabPc, $this> */
    public function labPcs(): HasMany
    {
        return $this->hasMany(LabPc::class);
    }

    /** @return HasMany<AccessPoint, $this> */
    public function accessPoints(): HasMany
    {
        return $this->hasMany(AccessPoint::class);
    }

    /**
     * "L6-IT-01 — Level 6 IT Lab 01", for a select or a table cell.
     *
     * The code comes first because that is what is printed on the door and
     * what an assistant reads out over the phone.
     */
    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
