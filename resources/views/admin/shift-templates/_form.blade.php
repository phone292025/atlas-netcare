{{-- Shared by create and edit.
     Included with ['template' => ShiftTemplate|null, 'locations' => array, 'days' => array]. --}}
<div class="space-y-4">
    <x-form.input name="name" label="Name" required :value="$template?->name"
                  placeholder="Morning lab cover"
                  help="What this slot is called on the roster." />

    <div class="grid gap-4 sm:grid-cols-2">
        {{-- Values are Carbon's dayOfWeek (0 = Sunday), listed Monday-first
             because that is how a timetable reads. --}}
        <x-form.select name="day_of_week" label="Day" required :options="$days"
                       :selected="$template?->day_of_week ?? 1" />

        <x-form.input name="required_assistants" label="Assistants needed" type="number" required
                      :value="$template?->required_assistants ?? 1"
                      help="One shift is generated per assistant." />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.input name="start_time" label="Starts" type="time" required
                      :value="$template ? substr($template->start_time, 0, 5) : '09:00'" />
        <x-form.input name="end_time" label="Ends" type="time" required
                      :value="$template ? substr($template->end_time, 0, 5) : '13:00'" />
    </div>

    <x-form.select name="location_id" label="Location" required :options="$locations"
                   :selected="$template?->location_id" placeholder="Choose a location" />

    <label class="flex items-center gap-2 text-sm text-fg-muted">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template?->is_active ?? true))
               class="rounded border-line-strong text-brand-600 focus:ring-brand-500">
        Active, so this slot is included when a roster is generated
    </label>
</div>
