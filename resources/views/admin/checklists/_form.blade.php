{{-- Shared by create and edit.
     Included with ['template' => ChecklistTemplate|null, 'categories' => array].

     REORDERING IS UP/DOWN BUTTONS, NOT DRAG.

     The module spec asked for drag-to-reorder. A drag library is a new
     dependency and would need an ADR, and HTML5 drag-and-drop does not work by
     touch — so the admin would be the one screen in the system that a tablet
     could not operate. Moving a row with a button works by mouse, by touch and
     by keyboard, and position in the submitted array is what becomes
     sort_order either way.

     The whole list posts as one form. Position IS the order, so saving a
     single row would have nothing to say about where it sits. --}}

@php
    $existing = $template?->items->map(fn ($item) => [
        'label' => $item->label,
        'category' => $item->category->value,
        'is_critical' => (bool) $item->is_critical,
    ])->values()->all() ?? [];

    // old() wins, so a validation failure does not throw away typing.
    $rows = old('items', $existing ?: [['label' => '', 'category' => 'boot', 'is_critical' => false]]);

    /*
     * Two normalisations, both load-bearing:
     *
     * `is_critical` is cast to a real boolean. Straight out of old() it is the
     * string "0" for an unticked box — which is truthy in JavaScript, so every
     * non-critical item would come back ticked after a validation error.
     *
     * `_key` gives each row a stable identity for x-for. Keying on the array
     * index while rows are being reordered is the classic Alpine trap: the
     * DOM node stays put and only the bound values move, so the disabled
     * state on the first and last move buttons ends up on the wrong rows.
     */
    $rows = array_values(array_map(fn ($row, $index) => [
        'label' => $row['label'] ?? '',
        'category' => $row['category'] ?? 'boot',
        'is_critical' => filter_var($row['is_critical'] ?? false, FILTER_VALIDATE_BOOLEAN),
        '_key' => $index,
    ], $rows, array_keys($rows)));
@endphp

<div class="space-y-4">
    <x-form.input name="name" label="Checklist name" required :value="$template?->name"
                  help="Assistants see this at the top of the form." />

    <x-form.textarea name="description" label="Description" rows="2" :value="$template?->description" />
</div>

<div class="mt-6" x-data="{
    rows: @js($rows),
    nextKey: @js(count($rows)),
    add() {
        this.rows.push({ label: '', category: 'boot', is_critical: false, _key: this.nextKey++ });
    },
    remove(index) {
        this.rows.splice(index, 1);
        // Never leave the list empty — the request requires at least one item,
        // and an empty list gives nowhere to start typing again.
        if (this.rows.length === 0) this.add();
    },
    move(index, by) {
        const target = index + by;
        if (target < 0 || target >= this.rows.length) return;
        const [row] = this.rows.splice(index, 1);
        this.rows.splice(target, 0, row);
    },
}">
    <div class="mb-2 flex items-end justify-between gap-2">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Items</h3>
            <p class="text-sm text-slate-500">
                Asked in this order. Mark an item <span class="font-medium">critical</span> when
                failing it means the machine cannot be used at all — that is what turns an
                inspection into a Fail rather than Pass with Issues.
            </p>
        </div>
        <button type="button" @click="add()"
                class="flex-none rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
            Add item
        </button>
    </div>

    @error('items')
        <p class="mb-2 text-sm text-red-600">{{ $message }}</p>
    @enderror

    <ul class="space-y-2">
        <template x-for="(row, index) in rows" :key="row._key">
            <li class="rounded-lg bg-white p-3 ring-1 ring-slate-200">
                <div class="flex items-start gap-2">
                    {{-- Move controls. Disabled at the ends rather than hidden,
                         so the row's controls do not shift position as it moves. --}}
                    <div class="flex flex-none flex-col gap-1 pt-1">
                        <button type="button" @click="move(index, -1)" :disabled="index === 0"
                                class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:hover:bg-transparent"
                                aria-label="Move up">
                            <x-icon name="chevron-up" class="h-4 w-4" />
                        </button>
                        <button type="button" @click="move(index, 1)" :disabled="index === rows.length - 1"
                                class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:hover:bg-transparent"
                                aria-label="Move down">
                            <x-icon name="chevron-down" class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="min-w-0 flex-1 space-y-2">
                        <input type="text"
                               :name="`items[${index}][label]`"
                               x-model="row.label"
                               placeholder="What should the assistant check?"
                               class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">

                        <div class="flex flex-wrap items-center gap-3">
                            <select :name="`items[${index}][category]`"
                                    x-model="row.category"
                                    class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                @foreach ($categories as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            <label class="flex items-center gap-2 text-sm text-slate-600">
                                {{-- The hidden field makes an unchecked box post
                                     0 rather than nothing, so unticking critical
                                     actually saves. --}}
                                <input type="hidden" :name="`items[${index}][is_critical]`" value="0">
                                <input type="checkbox" :name="`items[${index}][is_critical]`" value="1"
                                       x-model="row.is_critical"
                                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                Critical
                            </label>

                            <button type="button" @click="remove(index)"
                                    class="ml-auto rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            </li>
        </template>
    </ul>
</div>
