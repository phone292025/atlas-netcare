<x-app-layout>
    <x-slot name="title">Component gallery</x-slot>

    <div class="space-y-10">

        <x-alert type="warning" title="Development only">
            This page is registered only when <code>APP_ENV=local</code>. It is a
            living reference for the shared components, and a source of
            screenshots. It is not part of the system and is never deployed.
        </x-alert>

        {{-- ------------------------------------------------ page furniture --}}
        <section class="space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Page furniture</h2>

            <x-page-header title="Access points" subtitle="Library, IT Labs and Cafeteria.">
                <x-slot:actions>
                    <button type="button" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Add access point
                    </button>
                </x-slot:actions>
            </x-page-header>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-card title="A card" subtitle="With a subtitle and a footer.">
                    Body content sits here with the standard p-6 padding.
                    <x-slot:footer>Footers carry secondary actions or a summary.</x-slot:footer>
                </x-card>

                <x-card title="An empty state" flush>
                    <x-empty-state title="No tickets yet"
                                   message="Tickets raised by assistants during lab rounds appear here."
                                   icon="ticket" />
                </x-card>
            </div>
        </section>

        {{-- ------------------------------------------------------- feedback --}}
        <section class="space-y-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Feedback</h2>

            <x-alert type="success">Ticket resolved.</x-alert>
            <x-alert type="warning" title="Two access points degraded">Latency above 120&nbsp;ms for three consecutive readings.</x-alert>
            <x-alert type="error" title="Could not save" dismissible>Asset tag APU-1042 is already in use.</x-alert>
            <x-alert type="nonsense">An unrecognised type renders fuchsia on purpose, so a typo is visible rather than silently styled as a success.</x-alert>
        </section>

        {{-- --------------------------------------------------------- tones --}}
        <section class="space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Status vocabulary</h2>

            <x-card title="Every status enum in the system">
                <p class="mb-4 text-sm text-slate-500">
                    Each badge asks its own enum for the label and the tone. Colour comes from
                    <code>App\Support\Tone</code> — the single place that decides what a tone looks like.
                </p>

                <dl class="space-y-3 text-sm">
                    @foreach ([
                        'Access point' => \App\Enums\NetworkStatus::cases(),
                        'Lab PC' => \App\Enums\DeviceStatus::cases(),
                        'Ticket status' => \App\Enums\TicketStatus::cases(),
                        'Inspection' => \App\Enums\InspectionOutcome::cases(),
                        'Checklist item' => \App\Enums\ChecklistResult::cases(),
                        'Shift' => \App\Enums\ShiftStatus::cases(),
                        'Risk band' => \App\Enums\RiskBand::cases(),
                        'Alarm' => \App\Enums\AlarmType::cases(),
                    ] as $group => $cases)
                        <div class="sm:flex sm:gap-4">
                            <dt class="w-40 flex-none pt-0.5 text-slate-500">{{ $group }}</dt>
                            <dd class="mt-1 flex flex-wrap gap-2 sm:mt-0">
                                @foreach ($cases as $case)
                                    <x-status-badge :status="$case" />
                                @endforeach
                            </dd>
                        </div>
                    @endforeach

                    <div class="sm:flex sm:gap-4">
                        <dt class="w-40 flex-none pt-0.5 text-slate-500">Ticket priority</dt>
                        <dd class="mt-1 flex flex-wrap gap-2 sm:mt-0">
                            @foreach (\App\Enums\TicketPriority::cases() as $case)
                                <x-priority-pill :priority="$case" />
                            @endforeach
                        </dd>
                    </div>
                </dl>
            </x-card>
        </section>

        {{-- ----------------------------------------------------- dashboard --}}
        <section class="space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Dashboard</h2>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-stat-tile label="Access points online" value="37" unit="of 40" />
                <x-stat-tile label="Mean latency" value="142" unit="ms" delta="+18 ms" trend="up" tone="urgent" />
                <x-stat-tile label="Uptime" value="99.8" unit="%" delta="+0.2" trend="up" tone="healthy" />
                <x-stat-tile label="Open tickets" value="18" delta="-4" trend="down" tone="healthy" :href="route('profile.edit')" />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-card title="Risk, with its contributing factors">
                    <x-risk-gauge :score="78"
                                  :factors="['age' => 11.0, 'fault_frequency' => 22.5, 'critical_fail_rate' => 18.2, 'days_since_inspect' => 4.2]" />
                </x-card>

                <x-card title="Mean latency, 24 hours">
                    <x-chart type="line"
                             tone="attention"
                             label="Mean latency over 24 hours"
                             :height="200"
                             :data="['labels' => ['06','08','10','12','14','16','18','20'], 'datasets' => [['label' => 'ms', 'data' => [22, 38, 71, 96, 148, 131, 84, 41]]]]" />
                </x-card>
            </div>
        </section>

        {{-- --------------------------------------------------------- table --}}
        <section class="space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Data table</h2>

            <x-data-table :headers="[
                'Access point',
                ['label' => 'Status', 'sort' => 'status'],
                ['label' => 'Latency', 'sort' => 'latency_ms', 'align' => 'right'],
            ]">
                <x-slot:filters>
                    <span class="text-xs text-slate-500">Filter controls belong here.</span>
                </x-slot:filters>

                @foreach ([['AP-L6-01', \App\Enums\NetworkStatus::Online, 24], ['AP-L6-02', \App\Enums\NetworkStatus::Degraded, 187], ['AP-CAF-01', \App\Enums\NetworkStatus::Offline, 0]] as [$code, $status, $latency])
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium">{{ $code }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="$status" /></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $latency }}</td>
                    </tr>
                @endforeach

                <x-slot:pagination>
                    <x-pagination :paginator="$paginator" />
                </x-slot:pagination>
            </x-data-table>
        </section>

        {{-- ---------------------------------------------------------- forms --}}
        <section class="space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Forms</h2>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-card title="Controls">
                    <div class="space-y-4">
                        <x-form.input name="asset_tag" label="Asset tag" required
                                      help="Printed on the sticker on the machine's case." />
                        <x-form.input name="warranty_expiry" label="Warranty expires" type="date" />
                        <x-form.select name="priority" label="Priority"
                                       :options="\App\Enums\TicketPriority::options()" selected="medium" />
                        <x-form.select name="location_id" label="Location" placeholder="Choose a location"
                                       :options="['1' => 'Level 6 IT Lab 01', '2' => 'Library', '3' => 'Cafeteria']"
                                       help="placeholder — a disabled prompt. For forms, where you must pick something." />
                        <x-form.select name="location_filter" label="Location (filter bar)" blank="Anywhere"
                                       :options="['1' => 'Level 6 IT Lab 01', '2' => 'Library', '3' => 'Cafeteria']"
                                       help="blank — selectable. For filters, where the user has to be able to get back to &quot;any&quot;." />
                        <x-form.textarea name="handover_notes" label="Handover notes" rows="3"
                                         help="What the next shift needs to know." />
                    </div>
                </x-card>

                <x-card title="Ticket history">
                    <x-timeline :events="[
                        ['actor' => 'Swan Yee Htet', 'action' => 'raised the ticket', 'at' => now()->subHours(6), 'tone' => 'urgent'],
                        ['actor' => 'Nyan Lin Tun', 'action' => 'acknowledged it', 'at' => now()->subHours(5), 'tone' => 'attention'],
                        ['actor' => 'Nyan Lin Tun', 'action' => 'resolved the ticket', 'description' => 'Replaced the DisplayPort cable.', 'at' => now()->subHour(), 'tone' => 'healthy'],
                    ]" />
                </x-card>
            </div>
        </section>

        {{-- ----------------------------------------------------- inspection --}}
        <section class="space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                Inspection checklist &mdash; narrow your browser to 375px to see the mobile case
            </h2>

            <div class="max-w-md space-y-3">
                <x-checklist-item :item="(object) ['id' => 1, 'label' => 'Machine boots to the login screen', 'is_critical' => true]" />
                <x-checklist-item :item="(object) ['id' => 2, 'label' => 'Monitor displays correctly', 'is_critical' => false]" result="issue" />
                <x-checklist-item :item="(object) ['id' => 3, 'label' => 'Keyboard and mouse respond', 'is_critical' => false]" />
            </div>
        </section>

        {{-- ---------------------------------------------------------- modal --}}
        <section class="space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Modal</h2>

            <x-card>
                <button type="button"
                        @click="$dispatch('open-modal', 'gallery-demo')"
                        class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">
                    Deactivate account
                </button>

                <x-modal name="gallery-demo" title="Deactivate this account?">
                    <p>They will be signed out and cannot sign in again. Their tickets and
                       inspection history are kept, because deactivation is not deletion.</p>

                    <x-slot:footer>
                        <button type="button" @click="$dispatch('close-modal', 'gallery-demo')"
                                class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">
                            Cancel
                        </button>
                        <button type="button"
                                class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white">
                            Deactivate
                        </button>
                    </x-slot:footer>
                </x-modal>
            </x-card>
        </section>

        {{-- ---------------------------------------------------------- icons --}}
        <section class="space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Icons</h2>

            <x-card>
                <div class="grid grid-cols-3 gap-3 text-slate-600 sm:grid-cols-6">
                    @foreach (['calendar','clipboard','ticket','wifi','gauge','chart','wrench','users','building','desktop','shield','bell','menu','close','chevron-down','chevron-up','chevron-left','chevron-right','logout','user','check-circle','warning','x-circle','inbox','sort','plus','nonexistent'] as $name)
                        <div class="flex flex-col items-center gap-1 rounded-md p-2 ring-1 ring-slate-100">
                            <x-icon :name="$name" class="h-5 w-5" />
                            <span class="text-[10px] text-slate-400">{{ $name }}</span>
                        </div>
                    @endforeach
                </div>
                <x-slot:footer>
                    The last one is deliberately not a real icon name — an unknown name renders a
                    question mark so a typo shows on screen instead of leaving a gap.
                </x-slot:footer>
            </x-card>
        </section>

    </div>
</x-app-layout>
