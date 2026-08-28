<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftTemplate;
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
