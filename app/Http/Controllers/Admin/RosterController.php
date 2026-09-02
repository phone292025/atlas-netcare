<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Shifts\ShiftService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The week view, and the button that fills it (FR-408).
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class RosterController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function index(Request $request): View
    {
        $week = $this->week($request);

        $shifts = Shift::query()
            ->with(['user', 'location', 'template'])
            ->whereBetween('shift_date', [$week, $week->copy()->endOfWeek()])
            ->orderBy('shift_date')->orderBy('start_time')
            ->get();

        return view('admin.roster.index', [
            'week' => $week,
            // Grouped by date so the view renders a column per day without
            // filtering the collection seven times in Blade.
            'byDay' => $shifts->groupBy(fn (Shift $s) => $s->shift_date->toDateString()),
            'days' => collect(range(0, 6))->map(fn ($i) => $week->copy()->addDays($i)),
            'templateCount' => ShiftTemplate::query()->active()->count(),
            'shiftCount' => $shifts->count(),

            // FR-408. Fetched once for the whole week rather than per shift:
            // a fortnight of shifts would otherwise be a query each.
            'assistants' => User::query()->active()->role(UserRole::Assistant)
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Generate a week from the templates.
     *
     * Safe to press twice — the service is idempotent — which matters because
     * the obvious reaction to a slow page is to press the button again.
     */
    public function generate(Request $request): RedirectResponse
    {
        $week = $this->week($request);

        try {
            $created = $this->shifts->generateWeek($week);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.roster.index', ['week' => $week->toDateString()])
            ->with(
                $created->isEmpty() ? 'warning' : 'success',
                $created->isEmpty()
                    ? 'Nothing new to add — this week is already rostered from the current templates.'
                    : "{$created->count()} ".str('shift')->plural($created->count()).' created for the week of '.$week->format('j M').'.',
            );
    }

    /**
     * Move one shift to a different assistant (FR-408).
     *
     * The rules live in ShiftService, not here. A DomainException from it is
     * an expected outcome — "that shift has already started" is information,
     * not a crash — so it comes back as a flash message, the same shape as
     * generate() above.
     */
    public function reassign(Request $request, Shift $shift): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->shifts->reassign($shift, User::findOrFail($validated['user_id']));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            $shift->fresh()->user?->name.' now has the '.$shift->label().' shift on '
                .$shift->shift_date->format('D j M').'.',
        );
    }

    /**
     * The week being looked at, defaulting to this one.
     *
     * Always snapped to a Monday: the generator works in whole weeks, and a
     * roster starting on a Wednesday would silently generate a different set
     * of days from the one on screen.
     */
    private function week(Request $request): Carbon
    {
        return $request->filled('week')
            ? Carbon::parse($request->string('week'))->startOfWeek()
            : now()->startOfWeek();
    }
}
