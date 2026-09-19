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
         * There was no guard at all here once: an inspection could
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

            /*
             * EVERY ITEM ANSWERED, enforced here and not only in the form.
             *
             * deriveOutcome() looks for failures among the answers it has. An
             * item with no answer is not a failure, so a checklist with one
             * question answered and twelve skipped derived as Pass and stamped
             * the machine inspected: a machine certified working on questions
             * nobody asked, feeding M7's risk score. InspectionRequest has
             * refused that since M3 and its docblock calls it "the failure
             * mode that matters here" — but the rule lived only in the form,
             * so this method, called directly, accepted 1 of 13. Found by
             * driving the service against the live database.
             *
             * Checked after saving, against what the inspection now holds, so
             * a resubmission that corrects answers is judged on the whole set.
             * Throwing inside this transaction rolls back the answers just
             * written, so a refused inspection is left exactly as it was.
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
