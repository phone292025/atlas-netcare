<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Support\CampusClock;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One assistant, on one date, in one place.
 *
 * `status`, `checked_in_at` and `checked_out_at` are absent from $fillable:
 * they belong to ShiftService. A form that could set `checked_in_at` would let
 * somebody record attendance for a shift they never turned up to, which is the
 * one thing this table exists to be honest about.
 *
 * @see App\Services\Shifts\ShiftService
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'shift_template_id', 'user_id', 'location_id',
        'shift_date', 'start_time', 'end_time',
    ];

    /**
     * The column default, so a shift that has not been re-read from the
     * database does not report its status as null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ShiftStatus::Scheduled->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'shift_date' => 'date',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<ShiftTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    /** @return HasMany<Alarm, $this> */
    public function alarms(): HasMany
    {
        return $this->hasMany(Alarm::class);
    }

    /**
     * Inspections carried out during this shift.
     *
     * The foreign key was added by M6 — M3 created the column and left the
     * constraint for whenever `shifts` existed.
     *
     * @return HasMany<Inspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    /** @param  Builder<Shift>  $query */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /** @param  Builder<Shift>  $query */
    public function scopeOn(Builder $query, Carbon $date): void
    {
        $query->whereDate('shift_date', $date);
    }

    /** @param  Builder<Shift>  $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereDate('shift_date', '>=', CampusClock::today())->orderBy('shift_date')->orderBy('start_time');
    }

    /**
     * The moment this shift starts, combining the date and wall-clock columns.
     *
     * THE TIMEZONE ARGUMENT IS LOAD-BEARING. "09:00" means 09:00 on campus;
     * parsed without a zone in a UTC application it becomes 17:00 in Malaysia,
     * and every check-in window and reminder is eight hours out. Carbon then
     * compares absolute moments, so comparisons against now() are right.
     */
    public function startsAt(): Carbon
    {
        return Carbon::parse(
            $this->shift_date->toDateString().' '.$this->start_time,
            config('atlas.calendar.timezone'),
        );
    }

    public function endsAt(): Carbon
    {
        return Carbon::parse(
            $this->shift_date->toDateString().' '.$this->end_time,
            config('atlas.calendar.timezone'),
        );
    }

    /**
     * Whether this shift falls on today's date ON CAMPUS. `shift_date` is a
     * calendar date and is compared as one, never converted; isToday() would
     * compare it with UTC's today.
     */
    public function isToday(): bool
    {
        return $this->shift_date->toDateString() === CampusClock::today()->toDateString();
    }

    /** Checked in and not yet out. */
    public function isActive(): bool
    {
        return $this->status === ShiftStatus::CheckedIn;
    }

    /**
     * Whether check-in is allowed yet. Generous at the front, so somebody
     * arriving early is not told to wait.
     */
    public function canCheckInNow(): bool
    {
        if ($this->status !== ShiftStatus::Scheduled) {
            return false;
        }

        $grace = (int) config('atlas.shifts.check_in_grace_min', 15);

        return now()->between(
            $this->startsAt()->subMinutes($grace),
            $this->endsAt(),
        );
    }

    /** "Mon 1 Sep, 09:00–13:00" */
    public function label(): string
    {
        return $this->shift_date->format('D j M').', '
            .substr((string) $this->start_time, 0, 5).'–'
            .substr((string) $this->end_time, 0, 5);
    }
}
