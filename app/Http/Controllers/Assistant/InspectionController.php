<?php

namespace App\Http\Controllers\Assistant;

use App\Enums\InspectionOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\InspectionRequest;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Services\Maintenance\InspectionService;
use DomainException;
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
                ->latest('id')
                ->paginate(15),

            // Every room with machines in service, with how far its round
            // has got today. The same machines the room screen lists.
            'rooms' => $this->inspections->roundsToday(),

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
            'labPcs' => $this->inspections->roundIn($location),
            'next' => $this->inspections->nextToCheck($location),
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
         * An unfinished inspection has no summary (its outcome is still null),
         * so the assistant goes back to the form. Anybody else is refused: a
         * half-answered checklist is not evidence.
         */
        if ($inspection->isInProgress()) {
            abort_unless($inspection->user_id === request()->user()->id, 403);

            return redirect()->route('assistant.inspections.edit', $inspection);
        }

        $inspection->load(['labPc.location', 'user', 'template', 'results.item', 'results.ticket']);
        $room = $inspection->labPc?->location;

        return view('assistant.inspections.show', [
            'inspection' => $inspection,
            // Only for whoever did the round: the way on once its tickets are raised.
            'next' => $room && $inspection->user_id === request()->user()->id
                ? $this->inspections->nextToCheck($room, $inspection->labPc)
                : null,
        ]);
    }

    /** The checklist form itself — the most-used screen in the system. */
    public function edit(Inspection $inspection): View
    {
        $this->authorize('update', $inspection);

        $inspection->load(['labPc.location', 'template.items', 'results']);
        $machine = $inspection->labPc;
        $room = $machine->location;
        $round = $room ? $this->inspections->roundIn($room) : collect();
        $index = $round->search(fn ($inRoom) => $inRoom->is($machine));

        return view('assistant.inspections.edit', [
            // The room's plan, and "Machine 7 of 14" in the order the round follows.
            'round' => $round,
            'position' => $index === false ? null : $index + 1,
            'roomSize' => $round->count(),
            'next' => $room ? $this->inspections->nextToCheck($room, $machine) : null,
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

        // InspectionRequest refuses an incomplete checklist before this runs,
        // so the service should never refuse here. If it ever does, that is a
        // message for the assistant, not a 500.
        try {
            $this->inspections->complete(
                $inspection,
                $request->answers(),
                $request->validated('notes'),
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $machine = $inspection->labPc;
        $next = $request->input('then') === 'next' && $machine->location
            ? $this->inspections->nextToCheck($machine->location, $machine)
            : null;

        /*
         * A clean machine goes straight on to the next one in the room. One
         * with an issue stops at its summary first, because that is where its
         * ticket gets raised, and going straight on would bury it.
         */
        if ($next && $inspection->overall_result === InspectionOutcome::Pass) {
            try {
                $following = $this->inspections->start($next, $request->user());

                return redirect()
                    ->route('assistant.inspections.edit', $following)
                    ->with('success', "{$machine->asset_tag} passed. Now {$next->asset_tag}.");
            } catch (DomainException|RuntimeException) {
                // The next machine cannot be started after all: say what was saved.
            }
        }

        return redirect()
            ->route('assistant.inspections.show', $inspection)
            ->with('success', "{$machine->asset_tag} inspected.");
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
