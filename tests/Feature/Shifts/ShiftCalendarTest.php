<?php

namespace Tests\Feature\Shifts;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use App\Services\Shifts\ShiftCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Handing shifts to the assistant's own phone calendar.
 *
 * The file format is the whole feature. A calendar a phone silently refuses to
 * import is indistinguishable from a broken button, and the failure is only
 * visible on a real device, so the structure is asserted here instead.
 *
 * @see App\Services\Shifts\ShiftCalendar
 */
class ShiftCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function assistant(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        return $user->fresh();
    }

    /**
     * A shift with fixed times, so the calendar output can be asserted exactly.
     *
     * The location is only created when the caller has not supplied one.
     * Building it unconditionally and then discarding it collides with the
     * unique index on `locations.code`, which is the index doing its job.
     */
    private function shiftFor(User $user, array $overrides = []): Shift
    {
        $defaults = [
            'user_id' => $user->id,
            'shift_date' => '2026-09-01',
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
        ];

        if (! array_key_exists('location_id', $overrides)) {
            // Let the factory generate the code. Pinning it means two calls in
            // one test collide on the unique index, and no assertion here
            // depends on what the code actually is.
            $defaults['location_id'] = Location::factory()->create()->id;
        }

        return Shift::factory()->create(array_merge($defaults, $overrides));
    }

    // ------------------------------------------------------- the file itself

    public function test_the_file_is_a_valid_calendar_envelope(): void
    {
        $ics = app(ShiftCalendar::class)->forShift($this->shiftFor($this->assistant()));

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
    }

    public function test_lines_end_with_carriage_return_and_newline(): void
    {
        /*
         * Required by RFC 5545, not a preference. Some parsers tolerate a bare
         * newline; Outlook does not, and the failure is a file that silently
         * will not open.
         */
        $ics = app(ShiftCalendar::class)->forShift($this->shiftFor($this->assistant()));

        $this->assertSame(0, preg_match_all('/(?<!\r)\n/', $ics));
    }

    public function test_the_shift_time_is_local_and_not_shifted_to_utc(): void
    {
        /*
         * The bug worth guarding. The application runs in UTC while a shift
         * holds a local date and time, so emitting the raw value with a `Z`
         * would put a 09:00 shift in a Malaysian calendar at 17:00. A calendar
         * showing the wrong time is worse than no calendar.
         */
        $ics = app(ShiftCalendar::class)->forShift($this->shiftFor($this->assistant()));

        $this->assertStringContainsString('DTSTART;TZID=Asia/Kuala_Lumpur:20260901T090000', $ics);
        $this->assertStringContainsString('DTEND;TZID=Asia/Kuala_Lumpur:20260901T130000', $ics);

        // No floating UTC stamps on the event times.
        $this->assertStringNotContainsString('DTSTART:20260901T090000Z', $ics);
    }

    public function test_the_file_declares_the_timezone_it_uses(): void
    {
        // A TZID naming a zone the file never defines is what makes an import
        // fail on a client without its own zone database.
        $ics = app(ShiftCalendar::class)->forShift($this->shiftFor($this->assistant()));

        $this->assertStringContainsString('BEGIN:VTIMEZONE', $ics);
        $this->assertStringContainsString('TZID:Asia/Kuala_Lumpur', $ics);
        $this->assertStringContainsString('TZOFFSETTO:+0800', $ics);
    }

    public function test_the_event_carries_a_reminder(): void
    {
        // The entire point. Without the alarm the shift is only a diary entry,
        // and the assistant still has to remember to look.
        $ics = app(ShiftCalendar::class)->forShift($this->shiftFor($this->assistant()));

        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('TRIGGER:-PT30M', $ics);
    }

    public function test_the_event_id_is_stable_so_re_adding_does_not_duplicate(): void
    {
        // An assistant who taps the button twice should end up with one entry.
        $shift = $this->shiftFor($this->assistant());
        $calendar = app(ShiftCalendar::class);

        $this->assertStringContainsString(
            'UID:shift-'.$shift->id.'@atlas-netcare.apu',
            $calendar->forShift($shift),
        );
        $this->assertSame(
            $calendar->forShift($shift),
            $calendar->forShift($shift->fresh()),
        );
    }

    public function test_a_finished_shift_is_marked_cancelled(): void
    {
        // A shift already worked should not sit in the calendar as something
        // still to do.
        $shift = $this->shiftFor($this->assistant(), ['status' => ShiftStatus::Completed->value]);

        $this->assertStringContainsString('STATUS:CANCELLED', app(ShiftCalendar::class)->forShift($shift));
    }

    public function test_a_shift_with_no_status_in_memory_still_exports(): void
    {
        /*
         * `status` is not fillable and the column default supplies it, so a
         * model created and not reloaded has no status in memory. Found by
         * generating a test file from a console script, where it crashed the
         * export outright.
         */
        $shift = Shift::create([
            'user_id' => $this->assistant()->id,
            'location_id' => Location::factory()->create()->id,
            'shift_date' => '2026-09-01',
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
        ]);

        $this->assertNull($shift->status);

        $this->assertStringContainsString(
            'STATUS:CONFIRMED',
            app(ShiftCalendar::class)->forShift($shift),
        );
    }

    public function test_special_characters_are_escaped(): void
    {
        /*
         * Commas and semicolons are field separators in this format. An
         * unescaped one in a room name silently truncates the description, or
         * corrupts the event outright.
         */
        $user = $this->assistant();
        $shift = $this->shiftFor($user, [
            'location_id' => Location::factory()->create([
                'code' => 'LIB-L4',
                'name' => 'Library, Level 4; Quiet Study',
            ])->id,
        ]);

        $ics = app(ShiftCalendar::class)->forShift($shift);

        $this->assertStringContainsString('Library\\, Level 4\\; Quiet Study', $ics);
    }

    public function test_long_lines_are_folded(): void
    {
        // Lines over 75 octets must be folded or strict parsers reject the file.
        $shift = $this->shiftFor($this->assistant(), [
            'location_id' => Location::factory()->create([
                'code' => 'L6-IT-01',
                'name' => str_repeat('Very long location name ', 8),
            ])->id,
        ]);

        $ics = app(ShiftCalendar::class)->forShift($shift);

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Unfolded line: {$line}");
        }
    }

    // ------------------------------------------------------- the download

    public function test_an_assistant_can_download_their_own_shift(): void
    {
        $user = $this->assistant();
        $shift = $this->shiftFor($user);

        $this->actingAs($user)
            ->get(route('assistant.shifts.calendar', $shift))
            ->assertOk()
            ->assertHeader('content-type', 'text/calendar; charset=utf-8')
            ->assertSee('BEGIN:VEVENT', false);
    }

    public function test_an_assistant_cannot_download_somebody_elses_shift(): void
    {
        $shift = $this->shiftFor($this->assistant());

        $this->actingAs($this->assistant())
            ->get(route('assistant.shifts.calendar', $shift))
            ->assertForbidden();
    }

    // ------------------------------------------------------- the feed

    public function test_a_subscription_link_starts_switched_off(): void
    {
        // Nobody should have a live feed they never asked for.
        $this->assertNull($this->assistant()->calendar_token);
    }

    public function test_creating_a_link_makes_the_feed_readable_without_signing_in(): void
    {
        /*
         * The property the whole feature depends on. A calendar client fetches
         * this on a timer with no session and no way to be prompted, so it has
         * to work while signed out.
         */
        $user = $this->assistant();
        $this->shiftFor($user);

        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'))->assertRedirect();

        $token = $user->fresh()->calendar_token;
        $this->assertNotNull($token);

        // No actingAs. This is a signed-out request.
        $this->get(route('shifts.calendar.feed', $token))
            ->assertOk()
            ->assertSee('BEGIN:VEVENT', false);
    }

    public function test_an_unknown_token_is_a_flat_404(): void
    {
        // Not a 403, which would confirm that some tokens are valid.
        $this->get(route('shifts.calendar.feed', 'not-a-real-token'))->assertNotFound();
    }

    public function test_replacing_the_link_breaks_the_old_one(): void
    {
        // This is the revoke button. It has to actually revoke.
        $user = $this->assistant();
        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));
        $old = $user->fresh()->calendar_token;

        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));

        $this->assertNotSame($old, $user->fresh()->calendar_token);
        $this->get(route('shifts.calendar.feed', $old))->assertNotFound();
    }

    public function test_turning_the_link_off_stops_the_feed(): void
    {
        $user = $this->assistant();
        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));
        $token = $user->fresh()->calendar_token;

        $this->actingAs($user)->delete(route('assistant.shifts.calendar.revoke'));

        $this->assertNull($user->fresh()->calendar_token);
        $this->get(route('shifts.calendar.feed', $token))->assertNotFound();
    }

    public function test_the_feed_contains_only_that_persons_shifts(): void
    {
        $mine = $this->assistant();
        $theirs = $this->assistant();

        $this->shiftFor($mine);
        $otherShift = $this->shiftFor($theirs, ['shift_date' => '2026-09-02']);

        $this->actingAs($mine)->post(route('assistant.shifts.calendar.link'));

        $this->get(route('shifts.calendar.feed', $mine->fresh()->calendar_token))
            ->assertOk()
            ->assertDontSee('shift-'.$otherShift->id.'@', false);
    }

    public function test_the_feed_does_not_return_the_whole_history(): void
    {
        /*
         * A window, not everything. Returning every shift ever would grow
         * without limit and re-send hundreds of finished shifts on each
         * refresh.
         */
        $user = $this->assistant();
        $old = $this->shiftFor($user, ['shift_date' => today()->subYear()->toDateString()]);
        $soon = $this->shiftFor($user, ['shift_date' => today()->addDays(3)->toDateString()]);

        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));
        $response = $this->get(route('shifts.calendar.feed', $user->fresh()->calendar_token));

        $response->assertSee('shift-'.$soon->id.'@', false);
        $response->assertDontSee('shift-'.$old->id.'@', false);
    }

    public function test_the_feed_is_a_subscription_not_a_download(): void
    {
        // A client polling on a timer should read it in place, not be told to
        // save a file.
        $user = $this->assistant();
        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));

        $response = $this->get(route('shifts.calendar.feed', $user->fresh()->calendar_token));

        $this->assertStringStartsWith('inline', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('METHOD:PUBLISH', $response->getContent());
    }

    public function test_the_token_never_appears_in_serialised_output(): void
    {
        // Anyone holding it can read that person's roster without signing in.
        $user = $this->assistant();
        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));

        $this->assertArrayNotHasKey('calendar_token', $user->fresh()->toArray());
    }

    public function test_the_shift_screen_offers_a_single_shift_download(): void
    {
        // Works on every platform, and is the only calendar control shown by
        // default.
        $user = $this->assistant();
        $this->shiftFor($user, ['shift_date' => today()->toDateString()]);

        $this->actingAs($user)
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertSee('Add just this shift');
    }

    public function test_subscription_is_hidden_unless_it_is_switched_on(): void
    {
        /*
         * Off by default because a development server cannot host a feed a
         * calendar client will accept: the new Outlook takes only https:// and
         * refuses a private address, and Google fetches from its own servers
         * which cannot see a machine on somebody's Wi-Fi. A control that fails
         * for two of the three major clients is worse than no control.
         */
        $user = $this->assistant();
        $this->shiftFor($user, ['shift_date' => today()->toDateString()]);
        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));

        $this->actingAs($user->fresh())
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertDontSee('Put your roster in your phone');
    }

    public function test_subscribing_is_offered_as_a_one_click_protocol_link_when_enabled(): void
    {
        /*
         * `webcal://` is registered by calendar applications, so clicking it
         * opens Outlook or Calendar and offers to subscribe rather than
         * downloading a file the person then has to find and open.
         *
         * Only reachable once the system is deployed behind HTTPS, which is
         * what the flag represents.
         */
        config(['atlas.calendar.subscription' => true]);

        $user = $this->assistant();
        $this->shiftFor($user, ['shift_date' => today()->toDateString()]);

        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));

        $this->actingAs($user->fresh())
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertSee('webcal://', false)
            ->assertSee('Open in my calendar app');
    }

    public function test_the_feed_still_works_while_the_button_is_hidden(): void
    {
        // Hiding the control must not break anybody already subscribed, and
        // the capability has to survive for a deployment that can use it.
        $user = $this->assistant();
        $this->shiftFor($user);
        $this->actingAs($user)->post(route('assistant.shifts.calendar.link'));

        $this->get(route('shifts.calendar.feed', $user->fresh()->calendar_token))
            ->assertOk()
            ->assertSee('BEGIN:VEVENT', false);
    }
}
