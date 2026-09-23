<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The campus wall clock, and the one place the application asks what day it is.
 *
 * TWO CLOCKS, DELIBERATELY. Every moment is stored in UTC (`opened_at`,
 * `checked_in_at`, `recorded_at`), and comparing two moments never needs a
 * timezone. A CALENDAR question does: which day is today, which week, what the
 * clock on the lab wall says. On this campus that clock is eight hours ahead,
 * so from midnight to 08:00 UTC's "today" is campus yesterday.
 *
 * HOW TO USE IT.
 *
 *   - A calendar question (today, this week, is this shift today) goes
 *     through CampusClock::today() or ::now().
 *   - Showing a stored moment to a person goes through ->campus(), a macro
 *     registered in AppServiceProvider:
 *         {{ $ticket->opened_at?->campus()->format('j M Y, H:i') }}
 *   - A DATE column (`shift_date`, `purchase_date`) is not a moment and is
 *     never converted: 1 September is 1 September on every clock.
 *   - Stamping an event is still `now()`, in UTC.
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
     * A `Y-m-d` date from a URL, at midnight on campus, or null when the value
     * is missing or not a real date.
     *
     * Screens that take a date in the query string fall back to their default
     * on null. Parsing the raw value turned a hand-edited `?week=` into a 500.
     * Mixed, because `?week[]=` arrives as an array.
     */
    public static function dateFrom(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = rescue(fn () => Carbon::createFromFormat('!Y-m-d', $value, self::timezone()), report: false);

        // The round trip rejects dates PHP would roll over, like 2026-02-31.
        return $date instanceof Carbon && $date->format('Y-m-d') === $value ? $date : null;
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
