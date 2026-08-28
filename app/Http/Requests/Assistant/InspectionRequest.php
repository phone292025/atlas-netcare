<?php

namespace App\Http\Requests\Assistant;

use App\Enums\ChecklistResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A completed inspection: one answer per checklist item.
 *
 * Authorisation is InspectionPolicy, applied in the controller — the rule is
 * about this inspection and this person, which middleware cannot express.
 *
 * @see docs/03-requirements.md FR-204, FR-205
 */
class InspectionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * Every item must be answered. A part-filled inspection submitted
             * as complete is the failure mode that matters here: it looks
             * exactly like a machine that was checked, and the unanswered
             * questions silently derive as a Pass.
             *
             * N/A exists so there is always an honest answer available, which
             * is what makes requiring all of them reasonable.
             */
            'results' => ['required', 'array'],
            'results.*.result' => ['required', Rule::enum(ChecklistResult::class)],

            // Only read when the answer is an Issue — see InspectionService.
            'results.*.remark' => ['nullable', 'string', 'max:500'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'results.*.result.required' => 'Answer every item before submitting.',
            'results.required' => 'Answer every item before submitting.',
        ];
    }

    /**
     * Every item on the template must actually appear in the submission.
     *
     * The rules above cannot do this on their own. `results.*.result` only
     * fires for keys that are PRESENT — an item nobody touched sends no field
     * at all, so it has no key, so no rule runs against it. Without this
     * check a half-answered form validates cleanly and
     * InspectionService::deriveOutcome() sees no issues among the answers it
     * did get, and returns Pass: a machine certified working on the strength
     * of questions nobody answered.
     *
     * The error is attached to the specific item so the form can highlight it
     * rather than showing one message at the top of a list of twelve.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $inspection = $this->route('inspection');

                if (! $inspection) {
                    return;
                }

                $answered = array_keys($this->input('results', []));

                foreach ($inspection->template->items as $item) {
                    if (! in_array((string) $item->id, array_map('strval', $answered), true)) {
                        $validator->errors()->add(
                            "results.{$item->id}.result",
                            'Answer this item before submitting.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * The answers keyed by checklist item id.
     *
     * @return array<int, array{result: string, remark?: string|null}>
     */
    public function answers(): array
    {
        return $this->validated('results');
    }
}
