<?php

namespace App\Services\Maintenance;

use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Creating, versioning and activating checklist templates.
 *
 * THE ONE RULE: a template that has been used is never modified. An
 * inspection's answers point at specific `checklist_items`, so editing a used
 * template would rewrite what somebody recorded standing at a machine six
 * months ago — either against questions that did not exist then, or missing
 * ones that did.
 *
 * Editing therefore copies: version + 1, items duplicated, old version
 * deactivated and kept forever. Editing an unused template is a plain update,
 * because there is no history to protect and versioning a draft nobody has
 * used just fills the list with noise.
 *
 * @see docs/02-open-questions.md Q2
 * @see docs/modules/M3-checklists-and-inspections.md
 */
class ChecklistService
{
    /**
     * Apply an edit, versioning first if the template has been used.
     *
     * Returns the template the caller should now show — which may not be the
     * one passed in.
     *
     * @param  array{name: string, description?: string|null}  $attributes
     */
    public function update(ChecklistTemplate $template, array $attributes): ChecklistTemplate
    {
        if (! $template->isLocked()) {
            $template->update($attributes);

            return $template;
        }

        return $this->newVersionOf($template, $attributes);
    }

    /**
     * Copy a template to the next version, carrying its items across.
     *
     * @param  array{name?: string, description?: string|null}  $attributes
     */
    public function newVersionOf(ChecklistTemplate $template, array $attributes = []): ChecklistTemplate
    {
        return DB::transaction(function () use ($template, $attributes) {
            /*
             * The next version number comes from the highest version sharing
             * this name, not from $template->version + 1. Editing v2 while v3
             * exists would otherwise try to create a second v3.
             */
            $nextVersion = ChecklistTemplate::query()
                ->where('name', $attributes['name'] ?? $template->name)
                ->max('version');

            $copy = ChecklistTemplate::create([
                'name' => $attributes['name'] ?? $template->name,
                'description' => $attributes['description'] ?? $template->description,
            ]);

            $copy->forceFill([
                'version' => (int) $nextVersion + 1,
                'is_active' => false,
            ])->save();

            foreach ($template->items as $item) {
                $copy->items()->create([
                    'label' => $item->label,
                    'category' => $item->category,
                    'is_critical' => $item->is_critical,
                    'sort_order' => $item->sort_order,
                ]);
            }

            // The new version inherits being live, so an edit does not
            // silently leave the system with no active checklist.
            if ($template->is_active) {
                $this->activate($copy);
            }

            return $copy;
        });
    }

    /**
     * Make this the template new inspections use.
     *
     * Deactivates every other one in the same transaction. Two active
     * templates would make `ChecklistTemplate::current()` depend on row order,
     * and two assistants inspecting the same rack would answer different
     * questions.
     */
    public function activate(ChecklistTemplate $template): ChecklistTemplate
    {
        DB::transaction(function () use ($template) {
            ChecklistTemplate::query()
                ->where('id', '!=', $template->id)
                ->update(['is_active' => false]);

            $template->forceFill(['is_active' => true])->save();
        });

        return $template;
    }

    /**
     * Replace a template's items wholesale, in the given order.
     *
     * Only ever called on an unused template — a used one is versioned first,
     * and the copy is what gets edited.
     *
     * @param  array<int, array{label: string, category: string, is_critical?: bool}>  $items
     */
    public function replaceItems(ChecklistTemplate $template, array $items): ChecklistTemplate
    {
        return DB::transaction(function () use ($template, $items) {
            $template->items()->delete();

            foreach (array_values($items) as $index => $item) {
                $template->items()->create([
                    'label' => $item['label'],
                    'category' => $item['category'],
                    'is_critical' => (bool) ($item['is_critical'] ?? false),
                    // Position in the submitted array is the order. The form
                    // reorders by moving rows, so the index is the answer and
                    // a separate sort_order field would only disagree with it.
                    'sort_order' => $index + 1,
                ]);
            }

            return $template->load('items');
        });
    }

    /**
     * Add one item to the end of a template.
     */
    public function addItem(ChecklistTemplate $template, array $attributes): ChecklistItem
    {
        return $template->items()->create([
            'label' => $attributes['label'],
            'category' => $attributes['category'],
            'is_critical' => (bool) ($attributes['is_critical'] ?? false),
            'sort_order' => ((int) $template->items()->max('sort_order')) + 1,
        ]);
    }
}
