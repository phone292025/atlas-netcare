<?php

namespace App\Services\Shifts;

use App\Models\Shift;
use Illuminate\Support\Collection;

/**
 * Turn shifts into an iCalendar file (RFC 5545).
 *
 * WHY THIS EXISTS. M6's alarms only reach the notification bell inside the
 * app, so an assistant who is not looking at Atlas NetCare does not know their
 * shift starts in thirty minutes. Rather than build a way to push messages
 * out, this hands the shift to the phone, and the phone's own alarm system
 * does the reminding. That system already works with the app closed, the
 * screen locked and no internet.
 *
 * It also needs no email address at all, which is why this survived the
 * change on 2026-08-29 that gave every account a deliverable Gmail
 * plus-address so FR-102 could send a password reset link. Before that the
 * accounts were @apu.test and @atlas.local, reserved domains that can never
 * receive mail, and email was not an option for reminders at any price. It is
 * now, and a calendar file is still the better answer: it alarms with the app
 * closed, needs no SMTP account, and cannot end up in a spam folder.
 *
 * TIMEZONE IS THE THING TO GET RIGHT. A shift is stored as a date plus a local
 * time, and the application runs in UTC. Emitting the raw values with a `Z`
 * suffix would put every shift eight hours out, and a calendar showing the
 * wrong time is worse than no calendar. So every event carries an explicit
 * TZID and the file includes a VTIMEZONE describing it. Malaysia has no
 * daylight saving, which keeps that block to a fixed offset.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class ShiftCalendar
{
    /**
     * A calendar containing one shift, for the "add to my calendar" button.
     */
    public function forShift(Shift $shift): string
    {
        return $this->build(collect([$shift]), publish: false);
    }

    /**
     * A calendar containing many shifts, for a subscription feed.
     *
     * @param  Collection<int, Shift>  $shifts
     */
    public function forFeed(Collection $shifts): string
    {
        return $this->build($shifts, publish: true);
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @param  bool  $publish  a subscribed feed needs METHOD and a display name;
     *                         a one-off download does not, and Outlook treats a
     *                         downloaded file carrying METHOD as a meeting
     *                         invitation rather than an event
     */
    private function build(Collection $shifts, bool $publish): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Group Atlas//Atlas NetCare//EN',
            'CALSCALE:GREGORIAN',
        ];

        if ($publish) {
            $lines[] = 'METHOD:PUBLISH';
            $lines[] = 'X-WR-CALNAME:'.$this->escape(config('atlas.calendar.name'));
            $lines[] = 'X-WR-TIMEZONE:'.$this->timezone();

            // Ask subscribers to re-check hourly. Apple roughly honours it;
            // Google ignores it and refreshes on its own schedule, sometimes
            // only once a day. That is why the single-shift button still
            // exists for "I need this on my phone now".
            $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
            $lines[] = 'X-PUBLISHED-TTL:PT1H';
        }

        $lines = array_merge($lines, $this->timezoneBlock());

        foreach ($shifts as $shift) {
            $lines = array_merge($lines, $this->event($shift));
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF between lines is required by the spec, not a preference. Some
        // parsers accept bare newlines; Outlook is not one of them.
        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /** @return list<string> */
    private function event(Shift $shift): array
    {
        $location = $shift->location;

        $description = collect([
            $location?->label(),
            $shift->template?->name,
            'Check in through Atlas NetCare when you arrive.',
        ])->filter()->implode("\n");

        return array_filter([
            'BEGIN:VEVENT',

            /*
             * Stable per shift, so re-importing or re-syncing updates the
             * existing entry instead of creating a duplicate. An assistant who
             * taps the button twice should end up with one event.
             */
            'UID:shift-'.$shift->id.'@atlas-netcare.apu',

            'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART;TZID='.$this->timezone().':'.$this->localStamp($shift, $shift->start_time),
            'DTEND;TZID='.$this->timezone().':'.$this->localStamp($shift, $shift->end_time),
            'SUMMARY:'.$this->escape('IT Assistant shift'.($location ? ' — '.$location->code : '')),
            'DESCRIPTION:'.$this->escape($description),
            $location ? 'LOCATION:'.$this->escape($location->label()) : null,

            /*
             * A shift somebody already worked or missed should not sit in
             * their calendar as something still to do.
             *
             * Null-safe because `status` is not in Shift::$fillable and the
             * column default supplies it, so a freshly created model that has
             * not been reloaded has no status in memory. Reachable from a
             * console script or a seeder rather than from the app, but
             * crashing an export over it would be a poor trade. An unknown
             * status is treated as live, which is the safer wrong answer.
             */
            'STATUS:'.(($shift->status?->isOutstanding() ?? true) ? 'CONFIRMED' : 'CANCELLED'),
            'TRANSP:OPAQUE',

            ...$this->alarm(),

            'END:VEVENT',
        ], fn ($line) => $line !== null);
    }

    /**
     * The reminder the phone will fire.
     *
     * Shipped set, so the assistant does not have to configure anything to get
     * the benefit. They can change or delete it once it is in their calendar,
     * which is the whole advantage of handing the job to the phone.
     *
     * @return list<string>
     */
    private function alarm(): array
    {
        $minutes = (int) config('atlas.calendar.reminder_min');

        return [
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'DESCRIPTION:'.$this->escape('Your Atlas NetCare shift starts in '.$minutes.' minutes'),
            'TRIGGER:-PT'.$minutes.'M',
            'END:VALARM',
        ];
    }

    /**
     * The local wall-clock stamp for a shift, as YYYYMMDDTHHMMSS.
     *
     * No `Z` and no conversion. The date and time columns already hold campus
     * local time, and the TZID on the property says which zone that is.
     */
    private function localStamp(Shift $shift, string $time): string
    {
        return $shift->shift_date->format('Ymd').'T'
            .str_replace(':', '', substr($time, 0, 8));
    }

    private function timezone(): string
    {
        return (string) config('atlas.calendar.timezone');
    }

    /**
     * A minimal VTIMEZONE.
     *
     * Malaysia has never observed daylight saving, so one STANDARD block with
     * a fixed +08:00 offset describes it completely. A campus in a
     * DST-observing country would need DAYLIGHT blocks and the transition
     * rules; this is the easy case and it is worth saying so rather than
     * leaving the next person to wonder.
     *
     * @return list<string>
     */
    private function timezoneBlock(): array
    {
        $offset = $this->offset();

        return [
            'BEGIN:VTIMEZONE',
            'TZID:'.$this->timezone(),
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:'.$offset,
            'TZOFFSETTO:'.$offset,
            'TZNAME:'.$this->timezone(),
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    /** The zone's UTC offset as +HHMM, read from PHP rather than hardcoded. */
    private function offset(): string
    {
        $seconds = (new \DateTimeZone($this->timezone()))
            ->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));

        return sprintf('%s%02d%02d',
            $seconds < 0 ? '-' : '+',
            intdiv(abs($seconds), 3600),
            intdiv(abs($seconds) % 3600, 60),
        );
    }

    /**
     * Escape a TEXT value (RFC 5545 §3.3.11).
     *
     * Backslash first. Escaping it after the others would double-escape the
     * backslashes they just introduced.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n'],
            $value,
        );
    }

    /**
     * Fold lines over 75 octets, as the spec requires.
     *
     * A continuation line starts with one space, and the parser strips it.
     * Splitting on bytes rather than characters would cut a multi-byte
     * character in half, so this walks the string in whole characters and
     * measures the bytes.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > 74) {
                $out .= $current."\r\n ";
                $current = '';
            }

            $current .= $char;
        }

        return $out.$current;
    }
}
