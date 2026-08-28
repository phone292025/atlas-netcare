<?php

namespace App\Models;

use Database\Factories\ShiftTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring weekly slot (Q4).
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class ShiftTemplate extends Model
{
    /** @use HasFactory<ShiftTemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name', 'day_of_week', 'start_time', 'end_time',
        'location_id', 'required_assistants', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'required_assistants' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** @param  Builder<ShiftTemplate>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * 0 = Sunday, matching Carbon::dayOfWeek.
     *
     * Stated everywhere it is used, because the alternative convention
     * (1 = Monday) is equally common and the difference is a silent
     * off-by-one across the whole roster.
     */
    public function dayName(): string
    {
        return [
            'Sunday', 'Monday', 'Tuesday', 'Wednesday',
            'Thursday', 'Friday', 'Saturday',
        ][$this->day_of_week] ?? 'Unknown';
    }

    /** "Mondays 09:00–13:00" */
    public function label(): string
    {
        return $this->dayName().'s '
            .substr((string) $this->start_time, 0, 5).'–'
            .substr((string) $this->end_time, 0, 5);
    }
}
