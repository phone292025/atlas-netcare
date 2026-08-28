<?php

namespace App\Http\Requests\Admin;

use App\Enums\ChecklistCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A checklist template and all of its items, in one submission.
 *
 * Items are validated and saved with the template rather than one at a time,
 * because the order is part of the data: position in the submitted array *is*
 * `sort_order`. Saving an item on its own would have nothing to say about
 * where it sits.
 *
 * Authorisation is the `role:admin` middleware on the route group.
 */
class ChecklistTemplateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],

            /*
             * At least one item. A template with none would let an assistant
             * "complete" an inspection having answered nothing, and it would
             * derive a Pass — a machine certified working by a form that
             * asked no questions.
             */
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.category' => ['required', Rule::enum(ChecklistCategory::class)],
            'items.*.is_critical' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'A checklist needs at least one item.',
            'items.min' => 'A checklist needs at least one item.',
            'items.*.label.required' => 'Every item needs a question.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        // Without this, a blank third row reports "The items.2.label field is
        // required" — which is accurate and useless to the person reading it.
        $attributes = [];

        foreach (array_keys($this->input('items', [])) as $index) {
            $attributes["items.{$index}.label"] = 'item '.((int) $index + 1);
            $attributes["items.{$index}.category"] = 'category for item '.((int) $index + 1);
        }

        return $attributes;
    }

    /**
     * The items as ChecklistService::replaceItems() wants them, renumbered
     * from zero so a removed middle row does not leave a gap in the order.
     *
     * @return array<int, array{label: string, category: string, is_critical: bool}>
     */
    public function items(): array
    {
        return array_values(array_map(
            fn (array $item) => [
                'label' => trim($item['label']),
                'category' => $item['category'],
                'is_critical' => (bool) ($item['is_critical'] ?? false),
            ],
            $this->validated('items'),
        ));
    }
}
