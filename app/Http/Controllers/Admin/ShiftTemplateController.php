<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShiftTemplateRequest;
use App\Models\Location;
use App\Models\ShiftTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Weekly shift patterns (FR-407).
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class ShiftTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.shift-templates.index', [
            'templates' => ShiftTemplate::query()
                ->with('location')
                ->withCount('shifts')
                ->orderBy('day_of_week')->orderBy('start_time')->orderBy('id')
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.shift-templates.create', [
            'template' => null,
            'locations' => $this->locationOptions(),
            'days' => $this->dayOptions(),
        ]);
    }

    public function store(ShiftTemplateRequest $request): RedirectResponse
    {
        $template = ShiftTemplate::create($request->validated());

        return redirect()
            ->route('admin.shift-templates.index')
            ->with('success', "{$template->label()} added. Generate a roster to turn it into shifts.");
    }

    public function edit(ShiftTemplate $shiftTemplate): View
    {
        return view('admin.shift-templates.edit', [
            'template' => $shiftTemplate,
            // Deleting a pattern that has generated shifts would orphan the
            // shifts people worked, so the screen offers deactivation instead.
            'hasShifts' => $shiftTemplate->shifts()->exists(),
            'locations' => $this->locationOptions(),
            'days' => $this->dayOptions(),
        ]);
    }

    public function update(ShiftTemplateRequest $request, ShiftTemplate $shiftTemplate): RedirectResponse
    {
        $shiftTemplate->update($request->validated());

        return redirect()
            ->route('admin.shift-templates.index')
            ->with('success', "{$shiftTemplate->label()} updated. Shifts already generated keep their original times.");
    }

    /**
     * Deleting is allowed only while no shift has come from it.
     *
     * The foreign key is nullOnDelete, so deleting would orphan real worked
     * shifts rather than fail — which is worse than refusing, because nobody
     * would notice.
     */
    public function destroy(ShiftTemplate $shiftTemplate): RedirectResponse
    {
        if ($shiftTemplate->shifts()->exists()) {
            return back()->with('error', "{$shiftTemplate->label()} has generated shifts. Deactivate it instead. That stops new shifts without disturbing the ones people worked.");
        }

        $shiftTemplate->delete();

        return redirect()
            ->route('admin.shift-templates.index')
            ->with('success', 'Shift template deleted.');
    }

    /** @return array<int, string> */
    private function locationOptions(): array
    {
        return Location::orderBy('code')->get()->mapWithKeys(fn ($l) => [$l->id => $l->label()])->all();
    }

    /** @return array<int, string> */
    private function dayOptions(): array
    {
        // 0 = Sunday, matching Carbon::dayOfWeek. Listed Monday-first because
        // that is how a timetable reads, while keeping the stored values.
        return [
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday',
        ];
    }
}
