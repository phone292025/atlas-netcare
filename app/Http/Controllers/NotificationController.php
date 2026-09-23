<?php

namespace App\Http\Controllers;

use App\Enums\AlarmType;
use App\Models\AccessPoint;
use App\Models\Alarm;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notification bell and its list (FR-212).
 *
 * Shared by all three roles — everybody receives alarms, and what differs is
 * only which ones were raised for them. That is decided by
 * Alarm::scopeVisibleTo(), not by a route.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class NotificationController extends Controller
{
    public function __construct(private readonly AlarmService $alarms) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $alarms = $this->alarms->recentFor($user, 50);

        /*
         * Opening the list marks everything read. That is the behaviour people
         * expect from a bell with a count on it, and the alternative — a badge
         * that stays lit after you have read everything — is what makes people
         * stop looking at it.
         *
         * Read AFTER loading, so the page still shows which ones were new.
         */
        $this->alarms->markAllRead($user);

        return view('notifications.index', ['alarms' => $alarms]);
    }

    /**
     * Open one alarm and go to whatever it is about.
     *
     * A reminder about a ticket should land on the ticket, not on a list that
     * mentions it.
     */
    public function show(Request $request, Alarm $alarm): RedirectResponse
    {
        abort_unless($alarm->user_id === $request->user()->id, 403);

        $this->alarms->markRead($alarm);

        return redirect($this->destinationFor($alarm, $request->user()));
    }

    private function destinationFor(Alarm $alarm, User $viewer): string
    {
        $related = $alarm->related;

        return match (true) {
            $related instanceof Ticket && $viewer->isAssistant() => route('assistant.tickets.show', $related),
            // The engineer's own screen, with the assign and progress buttons.
            $related instanceof Ticket => route('engineer.tickets.show', $related),
            $related instanceof AccessPoint => route('engineer.aps.show', $related),
            $alarm->shift_id !== null => route('assistant.shifts.today'),
            $alarm->type === AlarmType::ShiftRemoved => route('assistant.shifts.index'),
            default => route('notifications.index'),
        };
    }
}
