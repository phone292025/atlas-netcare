{{-- Shared by create and edit.
     Included with ['accessPoint' => AccessPoint|null], beside $locations, $parents, $agents and $statuses. --}}
<div class="space-y-4">
    <x-form.input name="code" label="AP code" required :value="$accessPoint?->code"
                  help="The label on the unit, e.g. AP-L6-03. Stored uppercase." />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.select name="location_id" label="Location" required :options="$locations"
                       :selected="$accessPoint?->location_id" placeholder="Choose a location" />
        {{-- When the switch fails, its access points fail with it. Phase 2
             opens one incident for the switch instead of one each. --}}
        <x-form.select name="parent_id" label="Connected to" :options="$parents"
                       :selected="$accessPoint?->parent_id" blank="Not recorded"
                       help="The switch it plugs into. Add switches under Network devices." />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.input name="model" label="Model" :value="$accessPoint?->model"
                      placeholder="Cisco Aironet 2802i" />
        <x-form.select name="agent_id" label="Watched by" :options="$agents"
                       :selected="$accessPoint?->agent_id" blank="No agent yet"
                       help="The agent that measures it. Only that agent may send its readings." />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.input name="mac_address" label="MAC address" required
                      :value="$accessPoint?->mac_address"
                      placeholder="00:1B:44:11:3A:B7"
                      help="Dashes and lowercase are accepted and normalised." />
        <x-form.input name="ip_address" label="IP address" :value="$accessPoint?->ip_address"
                      placeholder="10.20.6.31" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        {{-- Capacity is the denominator of the saturation threshold M5 alarms
             on, so an AP with no capacity set can never be judged "crowded".
             That is why it is required rather than nullable. --}}
        <x-form.input name="client_capacity" label="Client capacity" type="number" required
                      :value="$accessPoint?->client_capacity ?? 50"
                      help="Used to work out how full the AP is." />
        <x-form.input name="installed_at" label="Installed" type="date"
                      :value="$accessPoint?->installed_at?->format('Y-m-d')" />
    </div>

    {{-- Administrative state, not measured state. An engineer sets
         Under maintenance to stop a planned outage paging anybody; the
         collector overwrites the other three from telemetry. --}}
    <x-form.select name="status" label="Status" required :options="$statuses"
                   :selected="$accessPoint?->status?->value"
                   help="Set Under maintenance during planned work to suppress alarms. The other states are kept up to date by the collector." />
</div>
