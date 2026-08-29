<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\User;
use App\Services\Shifts\ShiftCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Handing shifts to the assistant's own phone calendar.
 *
 * Two ways in, on purpose.
 *
 * `download` gives one shift, right now, behind normal authentication. It
 * works immediately on both platforms and is the answer to "I need this in my
 * phone before I forget".
 *
 * `feed` gives every upcoming shift and keeps giving them as administrators
 * generate new weeks. Subscribed once, it needs no further action. But
 * calendar applications cannot sign in, so it is authorised by a secret token
 * in the URL rather than by a session, and Google refreshes subscriptions on
 * its own slow schedule. Hence keeping both.
 *
 * @see App\Services\Shifts\ShiftCalendar
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class ShiftCalendarController extends Controller
{
    public function __construct(private readonly ShiftCalendar $calendar) {}

    /** One shift, for the button on the shift screen. */
    public function download(Request $request, Shift $shift): Response
    {
        // Your own shift only. Somebody else's roster is not yours to export.
        abort_unless($shift->user_id === $request->user()->id, 403);

        $shift->load(['location', 'template']);

        return $this->respond(
            $this->calendar->forShift($shift),
            'atlas-shift-'.$shift->shift_date->format('Y-m-d').'.ics',
            attachment: true,
        );
    }

    /**
     * The subscription feed.
     *
     * NO `auth` MIDDLEWARE, and that is not an oversight. A calendar client
     * fetches this on a timer with no session and no way to be prompted, so
     * the token is the credential. It is looked up in constant time by the
     * database's unique index, and a wrong or retired token is a flat 404
     * rather than a message confirming that some tokens are valid.
     */
    public function feed(string $token): Response
    {
        $user = User::query()->where('calendar_token', $token)->first();

        abort_if($user === null, 404);

        /*
         * A window, not everything. Two weeks back so a phone that syncs on
         * Monday still shows last Friday, and far enough forward to cover any
         * roster an administrator has generated. Returning the entire history
         * would grow without limit and re-send hundreds of finished shifts on
         * every refresh.
         */
        $shifts = Shift::query()
            ->forUser($user)
            ->with(['location', 'template'])
            ->whereBetween('shift_date', [today()->subWeeks(2), today()->addMonths(6)])
            ->orderBy('shift_date')->orderBy('start_time')
            ->get();

        return $this->respond($this->calendar->forFeed($shifts), 'atlas-shifts.ics');
    }

    /** Create or replace the subscription token. */
    public function regenerate(Request $request): RedirectResponse
    {
        $isNew = $request->user()->calendar_token === null;

        // 48 characters from a cryptographic source. Replacing it invalidates
        // every copy of the old link, which is how somebody revokes a feed
        // they have shared by accident.
        $request->user()->forceFill([
            'calendar_token' => Str::random(48),
        ])->save();

        return back()->with('success', $isNew
            ? 'Subscription link created. Add it to your phone calendar and your shifts will keep arriving.'
            : 'New link created. The old one has stopped working.');
    }

    public function revoke(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['calendar_token' => null])->save();

        return back()->with('success', 'Subscription link switched off. Shifts already in your calendar stay there.');
    }

    private function respond(string $ics, string $filename, bool $attachment = false): Response
    {
        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',

            /*
             * A download is an attachment so the phone hands it to the
             * calendar app. A subscription is NOT, because a client fetching
             * the feed on a timer should read it in place rather than be told
             * to save a file.
             */
            'Content-Disposition' => ($attachment ? 'attachment' : 'inline').'; filename="'.$filename.'"',

            // Rosters change. A cached feed would keep showing last week's.
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
