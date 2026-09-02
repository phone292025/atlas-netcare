<?php

namespace App\Services\Maintenance;

use App\Enums\ChecklistResult;
use App\Enums\DeviceStatus;
use App\Enums\InspectionOutcome;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starting, saving and finishing an inspection.
 *
 * The controller does none of this. Everything here has to happen together or
 * not at all — writing the answers, deriving the outcome, and stamping the
 * machine's `last_inspected_at` — and a half-finished inspection is worse than
 * no inspection, because it looks like the machine was checked.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 * @see docs/03-architecture.md — services hold the rules
 */
class InspectionService
{
    /**
     * Begin an inspection, or hand back the one already in progress.
     *
     * Idempotent on purpose. An assistant on bad campus Wi-Fi taps "start"
     * twice and gets one inspection, not two half-filled ones competing to be
     * the record of the same visit.
     */
    public function start(LabPc $labPc, User $user, ?ChecklistTemplate $template = null): Inspection
    {
        /*
         * A retired machine cannot be inspected.
         *
         * Retirement writes `status = retired` AND the soft-delete stamp
         * together, and every list, report and risk calculation excludes it —
         * so an inspection against one produces a record nothing else
         * acknowledges, on a machine that is physically gone from the room.
         *
         * There was no guard at all here until 2026-09-02: an inspection could
         * be started on a machine that was both retired and soft-deleted, and
         * nothing complained. Completed inspections on retired machines stay
         * readable; this only governs starting new ones.
         */
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
                /*
                 * Ignore anything not on this inspection's own template. The
                 * item id arrives from a form field name, so without this an
                 * edited request could attach an answer belonging to another
                 * template — and the outcome would then be derived partly
                 * from questions this inspection never asked.
                 */
                if (! $itemIds->contains((int) $itemId)) {
                    continue;
                }

                $result = ChecklistResult::tryFrom($answer['result'] ?? '');

                if (! $result) {
                    continue;
                }

                /*
                 * updateOrCreate against the (inspection_id, checklist_item_id)
                 * unique index, so a resubmitted form corrects the answers
                 * rather than doubling them.
                 */
                $inspection->results()->updateOrCreate(
                    ['checklist_item_id' => (int) $itemId],
                    [
                        'result' => $result,
                        // A remark only means something against an Issue.
                        // Keeping one on an item flipped back to OK would
                        // leave "left USB port dead" attached to a pass.
                        'remark' => $result->isFailure() ? ($answer['remark'] ?? null) : null,
                    ],
                );
            }

            $inspection->load('results.item');

            $inspection->forceFill([
                'overall_result' => $this->deriveOutcome($inspection),
                'notes' => $notes,
                'completed_at' => now(),
            ])->save();

            /*
             * The machine's own record of when it was last looked at. M2
             * leaves this column for M3 to write, M7 reads it as a risk
             * factor, and the lab PC show page renders it.
             */
            $inspection->labPc->forceFill(['last_inspected_at' => $inspection->completed_at])->save();

            return $inspection;
        });
    }

    /**
     * The whole point of the module, in three lines.
     *
     *   any critical item marked Issue -> Fail
     *   any other item marked Issue    -> PassWithIssues
     *   otherwise                      -> Pass
     *
     * N/A is not a failure. An assistant marking "external monitor" as N/A on
     * a machine that has no external monitor has not found a fault, and
     * counting it as one would make every such machine look broken.
     */
    public function deriveOutcome(Inspection $inspection): InspectionOutcome
    {
        $issues = $inspection->results->filter(
            fn ($result) => $result->result->isFailure()
        );

        if ($issues->isEmpty()) {
            return InspectionOutcome::Pass;
        }

        $hasCritical = $issues->contains(
            fn ($result) => (bool) $result->item?->is_critical
        );

        return $hasCritical
            ? InspectionOutcome::Fail
            : InspectionOutcome::PassWithIssues;
    }
}
