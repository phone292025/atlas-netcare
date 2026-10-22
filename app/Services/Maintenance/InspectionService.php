<?php

namespace App\Services\Maintenance;

use App\Enums\ChecklistResult;
use App\Enums\DeviceStatus;
use App\Enums\InspectionOutcome;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\InspectionResult;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\User;
use App\Support\CampusClock;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starting, saving and finishing an inspection.
 *
 * Writing the answers, deriving the outcome and stamping the machine's
 * `last_inspected_at` happen together or not at all: a half-finished
 * inspection looks like the machine was checked.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 * @see docs/03-architecture.md — services hold the rules
 */
class InspectionService
{
    /**
     * Begin an inspection, or hand back the one already in progress, so a
     * second tap of "start" on bad Wi-Fi does not create a competing record.
     */
    public function start(LabPc $labPc, User $user, ?ChecklistTemplate $template = null): Inspection
    {
        // Retired kit is excluded from every list, report and risk score, and
        // is physically gone from the room. Past inspections stay readable.
        if ($labPc->trashed() || $labPc->status === DeviceStatus::Retired) {
            throw new DomainException(
                'That machine has been retired, so it can no longer be inspected.'
            );
        }

        $existing = Inspection::query()
            ->inProgress()
            ->where('lab_pc_id', $labPc->id)
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $template ??= ChecklistTemplate::current();

        if (! $template) {
            // A real state on a fresh install, not an impossible one. Callers
            // must turn this into a message rather than a 500.
            throw new RuntimeException('No active checklist template.');
        }

        return Inspection::create([
            'lab_pc_id' => $labPc->id,
            'user_id' => $user->id,
            'checklist_template_id' => $template->id,
            'started_at' => now(),
        ]);
    }

    /**
     * Save the answers and finish the inspection.
     *
     * `$answers` is keyed by checklist item id:
     *   [12 => ['result' => 'issue', 'remark' => 'Left USB port dead'], ...]
     *
     * @param  array<int, array{result: string, remark?: string|null}>  $answers
     */
    public function complete(Inspection $inspection, array $answers, ?string $notes = null): Inspection
    {
        return DB::transaction(function () use ($inspection, $answers, $notes) {
            $itemIds = $inspection->template->items->pluck('id');

            foreach ($answers as $itemId => $answer) {
                // Only items on this inspection's own template. The id comes
                // from a form field name, and an edited request could
                // otherwise answer questions this inspection never asked.
                if (! $itemIds->contains((int) $itemId)) {
                    continue;
                }

                $result = ChecklistResult::tryFrom($answer['result'] ?? '');

                if (! $result) {
                    continue;
                }

                // updateOrCreate against the unique index, so a resubmission
                // corrects answers rather than doubling them.
                $inspection->results()->updateOrCreate(
                    ['checklist_item_id' => (int) $itemId],
                    [
                        'result' => $result,
                        // A remark only means something against an Issue.
                        'remark' => $result->isFailure() ? ($answer['remark'] ?? null) : null,
                    ],
                );
            }

            /*
             * Every item answered, enforced here as well as in the form. An
             * unanswered item is not a failure, so one answer out of thirteen
             * would otherwise derive Pass and certify the machine on questions
             * nobody asked. Checked against what the inspection now holds, and
             * the throw rolls back the answers just written.
             */
            $unanswered = $itemIds->diff($inspection->results()->pluck('checklist_item_id'));

            if ($unanswered->isNotEmpty()) {
                throw new DomainException(
                    'Answer every item before submitting: '.$unanswered->count()
                    .' of '.$itemIds->count().' still have no answer.'
                );
            }

            $inspection->load('results.item');

            $inspection->forceFill([
                'overall_result' => $this->deriveOutcome($inspection),
                'notes' => $notes,
                'completed_at' => now(),
            ])->save();

            // Read by M7 as a risk factor and shown on the lab PC page.
            $inspection->labPc->forceFill(['last_inspected_at' => $inspection->completed_at])->save();

            return $inspection;
        });
    }

    /**
     * The machines in a room, in round order (by asset tag), each with how it
     * stands today: `inspected_today_count`, and `today_outcome`, the value of
     * the latest outcome today or null. A fault fixed and checked again reads
     * as clean. Retired machines are left out.
     *
     * `completed_at` is a moment stored in UTC, so "today" is the campus day
     * as UTC instants, not whereDate(): that compares the UTC date, which is
     * the wrong eight hours.
     *
     * @return Collection<int, LabPc>
     */
    public function roundIn(Location $location): Collection
    {
        $today = CampusClock::todayInUtc();

        return $location->labPcs()
            ->inService()
            ->withCount(['inspections as inspected_today_count' => fn ($q) => $q->whereBetween('completed_at', $today)])
            ->addSelect(['today_outcome' => Inspection::query()
                ->select('overall_result')
                ->whereColumn('lab_pc_id', 'lab_pcs.id')
                ->whereBetween('completed_at', $today)
                ->latest('completed_at')
                ->limit(1)])
            ->orderBy('asset_tag')
            ->get();
    }

    /**
     * The machine a round in this room should check next: the first one in
     * room order, by asset tag, that nobody has checked today. Given the
     * machine just checked, it looks along the room from there and only then
     * back round to the start, so a round moves on instead of jumping back.
     * Null once every machine in the room is checked today.
     */
    public function nextToCheck(Location $location, ?LabPc $after = null): ?LabPc
    {
        $waiting = $location->labPcs()
            ->inService()
            ->whereDoesntHave('inspections', fn ($q) => $q->whereBetween('completed_at', CampusClock::todayInUtc()))
            ->when($after, fn ($q) => $q->whereKeyNot($after->getKey()))
            ->orderBy('asset_tag')
            ->get();

        if ($after === null) {
            return $waiting->first();
        }

        return $waiting->first(fn (LabPc $machine) => strcmp($machine->asset_tag, $after->asset_tag) > 0)
            ?? $waiting->first();
    }

    /**
     * The outcome of an inspection:
     *
     *   any critical item marked Issue -> Fail
     *   any other item marked Issue    -> PassWithIssues
     *   otherwise                      -> Pass
     *
     * N/A is not a failure: "external monitor" on a machine without one is not
     * a fault.
     */
    public function deriveOutcome(Inspection $inspection): InspectionOutcome
    {
        $results = $inspection->results;

        return match (true) {
            $results->contains(fn (InspectionResult $r) => $r->isCriticalFailure()) => InspectionOutcome::Fail,
            $results->contains(fn (InspectionResult $r) => $r->result->isFailure()) => InspectionOutcome::PassWithIssues,
            default => InspectionOutcome::Pass,
        };
    }
}
