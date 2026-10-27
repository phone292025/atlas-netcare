{{-- Shared by create and edit.
     Included with ['device' => NetworkDevice|null, 'kinds', 'locations', 'parents', 'agents', 'statuses']. --}}
<div class="space-y-4">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.select name="kind" label="Kind" required :options="$kinds"
                       :selected="$device?->kind?->value" placeholder="Choose a kind" />
        <x-form.input name="code" label="Code" required :value="$device?->code"
                      help="The label on the unit, e.g. SW-L6-01. Stored uppercase." />
    </div>

    <x-form.input name="name" label="Name" :value="$device?->name"
                  placeholder="Level 6 core switch"
                  help="Optional. What people call it." />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.select name="location_id" label="Location" required :options="$locations"
                       :selected="$device?->location_id" placeholder="Choose a location" />
        {{-- Faults below a device are grouped under it, so the list leaves out
             this device and everything connected below it: either would make
             a loop. --}}
        <x-form.select name="parent_id" label="Connected to" :options="$parents"
                       :selected="$device?->parent_id" blank="Nothing above it"
                       help="The device this one plugs into, if Atlas knows it." />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.input name="model" label="Model" :value="$device?->model"
                      placeholder="Cisco Catalyst 9200" />
        <x-form.select name="agent_id" label="Watched by" :options="$agents"
                       :selected="$device?->agent_id" blank="No agent yet"
                       help="The agent that measures it. Only that agent may send its readings." />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.input name="ip_address" label="IP address" :value="$device?->ip_address"
                      placeholder="10.20.6.1" />
        <x-form.input name="mac_address" label="MAC address" :value="$device?->mac_address"
                      placeholder="00:1B:44:11:3A:B7"
                      help="Optional. Dashes and lowercase are accepted." />
    </div>

    <x-form.input name="installed_at" label="Installed" type="date"
                  :value="$device?->installed_at?->format('Y-m-d')" />

    <x-form.select name="status" label="Status" required :options="$statuses"
                   :selected="$device?->status?->value ?? 'online'"
                   help="Set Under maintenance during planned work to suppress alarms. Readings keep the other states up to date." />
</div>
