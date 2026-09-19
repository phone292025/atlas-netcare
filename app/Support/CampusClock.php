<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The campus wall clock, and the one place the application asks what day it is.
 *
 * TWO CLOCKS, DELIBERATELY. The application runs in UTC and stores every
 * moment in UTC: `opened_at`, `checked_in_at`, `recorded_at`. That is right and
 * stays right — an instant is an instant, and comparing two of them never
 * needs a timezone. What UTC cannot answer is a CALENDAR question: which day is
 * today, which week is this, what time does the clock on the lab wall say.
 * Those have an answer only on a particular clock, and on this campus that
 * clock is eight hours ahead of UTC.
 *
 * WHAT WENT WRONG WITHOUT IT. Every calendar question was answered in UTC:
 *
 *   - "My shift" asked for `today()`. From midnight until 08:00 on campus,
 *     UTC is still yesterday, so an assistant opening the app at 07:30 on a
 *     Monday was told they had no shift, while rostered for 09:00.
 *   - Every clock time on screen printed the stored UTC value. An assistant who
 *     checked in three minutes early to a 17:28 shift was shown "Checked in at
 *     09:25" beside "Opens 17:13" — two clocks on one screen.
 *   - The scheduler read "daily at 02:00" as 02:00 UTC, which is 10:00 on
 *     campus: risk scoring ran in the middle of the working day it was meant to
 *     finish before.
 *
 * HOW TO USE IT.
 *
 *   - A calendar question — today, this week, is this shift today — goes
 *     through CampusClock::today() or ::now().
 *   - Showing a stored moment to a person goes through ->campus() on the date,
 *     a macro registered in AppServiceProvider:
 *         {{ $ticket->opened_at?->campus()->format('j M Y, H:i') }}
 *   - A DATE column (`shift_date`, `purchase_date`) is not a moment and must
 *     not be converted. 1 September is 1 September on every clock.
 *   - Stamping an event is still `now()`, in UTC. Nothing about storage moves.
 *
 * The zone is `atlas.calendar.timezone`, which predates this class and was
 * already documented as "the campus wall clock": Shift::startsAt() and the
 * calendar export have always read it.
 *
 * @see config/atlas.php — calendar.timezone
 * @see docs/progress/decision-log.md — 2026-09-19
 */
final class CampusClock
{
    public static function timezone(): string
    {
        return (string) config('atlas.calendar.timezone');
    }

    /** The time on the lab wall. For deciding what day or week it is, never for stamping. */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /** Midnight at the start of today, on campus. */
    public static function today(): Carbon
    {
        return Carbon::today(self::timezone());
    }

    /**
     * Today on campus as the UTC instants it spans, for a column that stores
     * moments.
     *
     * `whereDate('completed_at', today())` compares the UTC DATE of a UTC
     * value, which is the wrong eight hours of the campus day. A campus day
     * runs from 16:00 UTC the day before to 16:00 UTC; this returns exactly
     * that, ready for whereBetween().
     *
     * @return array{Carbon, Carbon}
     */
    public static function todayInUtc(): array
    {
        $start = self::today();

        return [$start->copy()->utc(), $start->copy()->addDay()->subSecond()->utc()];
    }
}
