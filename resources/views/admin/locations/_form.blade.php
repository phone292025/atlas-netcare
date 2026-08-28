{{-- Shared by create and edit. Included with ['location' => Location|null, 'types' => array]. --}}
<div class="space-y-4">
    <x-form.input name="code" label="Location code" required :value="$location?->code"
                  help="What is printed on the door, e.g. L6-IT-01. Stored uppercase." />

    <x-form.input name="name" label="Name" required :value="$location?->name"
                  help="How people refer to it in conversation." />

    <x-form.select name="type" label="Type" required :options="$types"
                   :selected="$location?->type" placeholder="Choose a type" />

    <x-form.input name="floor" label="Floor" :value="$location?->floor" />

    <x-form.textarea name="description" label="Notes" rows="2" :value="$location?->description" />
</div>
