<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\Shifts\ShiftService;
use App\Support\CampusClock;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * An assistant's own shift (FR-201, FR-210, FR-211).
 *
 * `assistant.shifts.today` is the assistant's HOME SCREEN — it is where
 * UserRole::homeRoute() sends them after signing in, so it is the first thing
 * they see and has to work when they have no shift at all.
 *
 * @see App\Enums\UserRole::homeRoute()
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    /** Today, and what to do about it. */
    public function today(Request $request): View
    {
        $user = $request->user();
        $shift = $this->shifts->todayFor($user);

        return view('assistant.shifts.today', [
            'shift' => $shift,
            // The handover left by whoever was in this location before.
            'handover' => $shift ? $this->previousHandover($shift) : null,
            // What comes AFTER the shift shown above, so today's does not
            // appear twice. Two shifts on one day is possible, so exclude by
            // id rather than by date.
            'upcoming' => Shift::query()->forUser($user)->upcoming()
                ->when($shift, fn ($q) => $q->whereKeyNot($shift->id))
                ->with('location')->limit(5)->get(),
        ]);
    }

    /** The week ahead. */
    public function index(Request $request): View
    {
        // Both on the campus clock, or Monday before 08:00 shows last week.
        $week = $request->filled('week')
            ? Carbon::parse($request->string('week'), CampusClock::timezone())->startOfWeek()
            : CampusClock::now()->startOfWeek();

        return view('assistant.shifts.index', [
            'week' => $week,
            'days' => collect(range(0, 6))->map(fn ($i) => $week->copy()->addDays($i)),
            'byDay' => Shift::query()
                ->forUser($request->user())
                ->with('location')
                ->whereBetween('shift_date', [$week, $week->copy()->endOfWeek()])
                ->orderBy('start_time')
                ->get()
                ->groupBy(fn (Shift $s) => $s->shift_date->toDateString()),
        ]);
    }

    public function checkIn(Request $request, Shift $shift): RedirectResponse
    {
        try {
            $this->shifts->checkIn($shift, $request->user());
        } catch (DomainException $e) {
            // Ownership and timing are both real conditions an assistant can
            // hit honestly — a 403 would be accurate for the first and wrong
            // for the second, so both come back as a message.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Checked in. Have a good shift.');
    }

    public function checkOut(Request $request, Shift $shift): RedirectResponse
    {
        $validated = $request->validate([
            'handover_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->shifts->checkOut($shift, $request->user(), $validated['handover_notes'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', filled($validated['handover_notes'] ?? null)
            ? 'Checked out. Your notes will reach the next shift when it starts.'
            : 'Checked out.');
    }

    /**
     * The most recent handover for this location, if anyone left one.
     *
     * Shown on arrival rather than only sent as an alarm, because an assistant
     * who was not on shift when the alarm fired would otherwise never see it.
     */
    private function previousHandover(Shift $shift): ?Shift
    {
        return Shift::query()
            ->where('location_id', $shift->location_id)
            ->where('id', '!=', $shift->id)
            ->whereNotNull('handover_notes')
            ->whereNotNull('checked_out_at')
            ->with('user')
            ->latest('checked_out_at')
            ->first();
    }
}
