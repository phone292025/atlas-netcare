{{-- Shared by create and edit.
     Included with ['labPc' => LabPc|null, 'locations' => array, 'statuses' => array]. --}}
<div class="space-y-4">
    <x-form.input name="asset_tag" label="Asset tag" required :value="$labPc?->asset_tag"
                  help="Printed on the sticker on the case. Stored uppercase." />

    <x-form.select name="location_id" label="Location" required :options="$locations"
                   :selected="$labPc?->location_id" placeholder="Choose a location" />

    <x-form.input name="hostname" label="Hostname" :value="$labPc?->hostname" />

    <x-form.select name="status" label="Status" required :options="$statuses"
                   :selected="$labPc?->status?->value"
                   help="Degraded means working but below standard. Faulty means not working." />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.input name="cpu" label="CPU" :value="$labPc?->cpu" />
        <x-form.input name="ram_gb" label="RAM (GB)" type="number" :value="$labPc?->ram_gb" />
        <x-form.input name="storage" label="Storage" :value="$labPc?->storage" />
        <x-form.input name="os_version" label="Operating system" :value="$labPc?->os_version" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        {{-- Age is one of the four factors in M7's risk score, which is why the
             purchase date is worth capturing before M7 exists. --}}
        <x-form.input name="purchase_date" label="Purchased" type="date"
                      :value="$labPc?->purchase_date?->format('Y-m-d')"
                      help="Used by predictive maintenance to weigh age." />
        <x-form.input name="warranty_expiry" label="Warranty expires" type="date"
                      :value="$labPc?->warranty_expiry?->format('Y-m-d')" />
    </div>
</div>
