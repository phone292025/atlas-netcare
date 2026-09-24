<x-app-layout>
    <x-slot name="title">Profile</x-slot>

    @php($forced = (bool) $user->must_change_password)

    <div class="mx-auto max-w-4xl">
        <section class="mb-8 flex items-center gap-4">
            <x-avatar :name="$user->name" size="xl" />
            <div class="min-w-0">
                <h2 class="truncate text-2xl font-semibold tracking-tight text-fg">{{ $user->name }}</h2>
                <p class="truncate text-sm text-fg-muted">{{ $user->email }} · {{ $user->role?->label() ?? 'No role assigned' }}</p>
            </div>
        </section>

        {{-- Settings, one section a row: what it is on the left, the form on
             the right. A forced password change puts that section first, since
             it is the reason they are here. --}}
        <div class="divide-y divide-line">
            @if ($forced)
                @include('profile.partials.update-password-form', ['highlight' => true])
            @endif

            @include('profile.partials.update-profile-information-form')

            @unless ($forced)
                @include('profile.partials.update-password-form', ['highlight' => false])
            @endunless

            <section class="grid gap-4 py-8 first:pt-0 md:grid-cols-[15rem_minmax(0,1fr)] md:gap-8">
                <header>
                    <h3 class="text-[15px] font-semibold text-fg">Appearance</h3>
                    <p class="mt-1 text-sm text-fg-muted">How Atlas NetCare looks on this device.</p>
                </header>

                <div class="rounded-xl border border-line bg-surface p-5 shadow-xs">
                    <fieldset>
                        <legend class="text-sm font-medium text-fg">Theme</legend>
                        <div class="mt-3 grid grid-cols-3 gap-2" x-data>
                            @foreach (['system' => ['Match my device', 'theme-system'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon']] as $value => [$label, $icon])
                                <label class="cursor-pointer">
                                    <input type="radio" name="theme" value="{{ $value }}" class="peer sr-only"
                                           :checked="$store.theme.choice === '{{ $value }}'"
                                           @change="$store.theme.set('{{ $value }}')">
                                    <span class="flex flex-col items-center gap-2 rounded-lg border border-line-strong px-2 py-3 text-center text-xs text-fg-muted transition
                                                 hover:bg-sunken peer-checked:border-brand-600 peer-checked:bg-brand-50/60 peer-checked:font-medium peer-checked:text-brand-800
                                                 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                        <x-icon :name="$icon" class="size-5" />
                                        {{ $label }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-fg-subtle">Saved in this browser. Your other devices keep their own setting.</p>
                    </fieldset>
                </div>
            </section>
        </div>

        {{-- Account deletion is deliberately absent. Atlas NetCare deactivates
             accounts rather than deleting them, so audit history survives
             (NFR-06). Only an administrator can do it.
             @see docs/modules/M1-identity-and-audit.md --}}
    </div>
</x-app-layout>
