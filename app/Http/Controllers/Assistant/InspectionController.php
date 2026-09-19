<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\InspectionRequest;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Services\Maintenance\InspectionService;
use App\Support\CampusClock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An assistant working down a rack of machines (FR-203, FR-204, FR-205).
 *
 * The screens here are the ones used standing up, on a phone, one-handed.
 * NFR-05 is tested at 375px and this is what it is tested on.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 */
class InspectionController extends Controller
{
    public function __construct(private readonly InspectionService $inspections) {}

    /**
     * My rounds: anything unfinished first, then what I have completed.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Inspection::class);

        return view('assistant.inspections.index', [
            'inProgress' => Inspection::query()
                ->with(['labPc.location'])
                ->where('user_id', $request->user()->id)
                ->inProgress()
                ->latest('started_at')
                ->get(),

            'completed' => Inspection::query()
                ->with(['labPc.location'])
                ->where('user_id', $request->user()->id)
                ->completed()
                ->latest('completed_at')
                ->paginate(15),

            /*
             * whereHas, not having(). `withCount` adds a correlated subquery
             * column, not an aggregate, so HAVING against its alias is invalid
             * SQL without a GROUP BY — SQLite rejects it outright with
             * "HAVING clause on a non-aggregate query".
             *
             * Counting and filtering both go through inService(), so the
             * number on this screen matches the list on the next one.
             */
            'locations' => Location::query()
                ->withCount(['labPcs' => fn ($q) => $q->inService()])
                ->whereHas('labPcs', fn ($q) => $q->inService())
                ->orderBy('code')
                ->get(),

            'hasTemplate' => ChecklistTemplate::current() !== null,
        ]);
    }

    /**
     * The machines in one location, and whether each has been checked today.
     *
     * "Today" rather than "ever": a round is a daily thing, and a machine
     * inspected last week still needs looking at.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', Inspection::class);

        $location = Location::findOrFail($request->integer('location'));

        return view('assistant.inspections.create', [
            'location' => $location,
            'labPcs' => $location->labPcs()
                ->inService()
                // `completed_at` is a moment stored in UTC, so "today" is the
                // campus day expressed as UTC instants, not whereDate(): that
                // compares the UTC date, which is the wrong eight hours.
                ->withCount(['inspections as inspected_today_count' => fn ($q) => $q
                    ->whereBetween('completed_at', CampusClock::todayInUtc())])
                ->orderBy('asset_tag')
                ->get(),
            'hasTemplate' => ChecklistTemplate::current() !== null,
        ]);
    }

    /**
     * Start (or resume) an inspection for one machine, then go to the form.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Inspection::class);

        $labPc = LabPc::findOrFail($request->integer('lab_pc_id'));

        try {
            $inspection = $this->inspections->start($labPc, $request->user());
        } catch (RuntimeException) {
            // A fresh install with no checklist yet. A real state, and the
            // assistant can do nothing about it — say so instead of 500ing.
            return back()->with('error', 'There is no active checklist yet. An administrator needs to set one up before inspections can start.');
        }

        return redirect()->route('assistant.inspections.edit', $inspection);
    }

    /** The summary of a submitted inspection. */
    public function show(Inspection $inspection): View|RedirectResponse
    {
        $this->authorize('view', $inspection);

        /*
         * An inspection still in progress has no summary to show yet, so the
         * assistant goes to the form they were part-way through. Anybody else
         * is refused: a half-answered checklist is not evidence, and the
         * engineer and administrator read access this route exists for is
         * access to a SUBMITTED inspection.
         *
         * The redirect used to be missing. The guard ran, and then the summary
         * rendered anyway — and it opens with
         * <x-status-badge :status="$inspection->overall_result" /> on a column
         * that stays null until submission, so the one person entitled to look
         * at it got a 500 calling tone() on null.
         */
        if ($inspection->isInProgress()) {
            abort_unless($inspection->user_id === request()->user()->id, 403);

            return redirect()->route('assistant.inspections.edit', $inspection);
        }

        return view('assistant.inspections.show', [
            'inspection' => $inspection->load(['labPc.location', 'user', 'template', 'results.item']),
        ]);
    }

    /** The checklist form itself — the most-used screen in the system. */
    public function edit(Inspection $inspection): View
    {
        $this->authorize('update', $inspection);

        $inspection->load(['labPc.location', 'template.items', 'results']);

        return view('assistant.inspections.edit', [
            'inspection' => $inspection,
            // Grouped so the form has section headings in the order somebody
            // actually checks a machine. See ChecklistCategory::sortOrder().
            'sections' => $inspection->template->items
                ->groupBy(fn ($item) => $item->category->value)
                ->sortBy(fn ($items, $key) => $items->first()->category->sortOrder()),
            // Answers already saved, so a resumed inspection is not blank.
            'saved' => $inspection->results->keyBy('checklist_item_id'),
        ]);
    }

    /** Submit the completed checklist. */
    public function update(InspectionRequest $request, Inspection $inspection): RedirectResponse
    {
        $this->authorize('update', $inspection);

        $this->inspections->complete(
            $inspection,
            $request->answers(),
            $request->validated('notes'),
        );

        return redirect()
            ->route('assistant.inspections.show', $inspection)
            ->with('success', "{$inspection->labPc->asset_tag} inspected.");
    }

    /** Abandon a round that was started and not submitted. */
    public function destroy(Inspection $inspection): RedirectResponse
    {
        $this->authorize('delete', $inspection);

        $inspection->delete();

        return redirect()
            ->route('assistant.inspections.index')
            ->with('success', 'Inspection abandoned. Nothing was recorded against the machine.');
    }
}
