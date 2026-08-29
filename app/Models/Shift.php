<?php

namespace App\Models;

use App\Enums\ShiftStatus;
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
        $query->whereDate('shift_date', '>=', today())->orderBy('shift_date')->orderBy('start_time');
    }

    /**
     * The moment this shift starts, as a real datetime.
     *
     * `shift_date` and `start_time` are separate columns, a date and a wall
     * clock, so anything asking "has it started yet?" has to combine them.
     *
     * THE TIMEZONE ARGUMENT IS LOAD-BEARING. An administrator entering "09:00"
     * means 09:00 on campus. The application runs in UTC, so parsing without a
     * zone produced 09:00 UTC, which is 17:00 in Malaysia. Every comparison
     * built on it was eight hours out: an assistant could only check into a
     * 09:00 shift between 16:45 and 21:00, the reminder fired in the evening,
     * and a finished shift was not marked missed until the small hours.
     *
     * Carbon compares absolute moments, so once the zone is right every
     * comparison against now() is right, whatever config('app.timezone') is
     * set to. Setting the application timezone to the campus one would also
     * fix this, and would leave these calls correct rather than
     * double-converted.
     *
     * @see docs/progress/decision-log.md — 2026-08-29
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

    public function isToday(): bool
    {
        return $this->shift_date->isToday();
    }

    /** Checked in and not yet out. */
    public function isActive(): bool
    {
        return $this->status === ShiftStatus::CheckedIn;
    }

    /**
     * Whether check-in is allowed yet.
     *
     * Deliberately generous at the front — an assistant arriving fifteen
     * minutes early should not be told to wait, and a system that refuses
     * teaches people to ignore it. The window is config, not a literal.
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
