<section class="grid gap-4 py-8 first:pt-0 md:grid-cols-[15rem_minmax(0,1fr)] md:gap-8">
    <header>
        <h3 class="text-[15px] font-semibold text-fg">Change password</h3>
        <p class="mt-1 text-sm text-fg-muted">A long one you do not use anywhere else. A phrase of a few words works well.</p>
    </header>

    {{-- Two forms share this page, so this one validates into its own named
         error bag. Without the `bag` prop the fields would look for their
         errors in the default bag and silently show nothing. --}}
    <form method="post" action="{{ route('password.update') }}"
          @class([
              'rounded-xl border bg-surface shadow-xs',
              'border-amber-300 ring-1 ring-amber-300' => $highlight ?? false,
              'border-line' => ! ($highlight ?? false),
          ])>
        @csrf
        @method('put')

        <div class="space-y-4 p-5">
            <x-form.input name="current_password" label="Current password" type="password"
                          bag="updatePassword" autocomplete="current-password" />

            <x-form.input name="password" label="New password" type="password"
                          bag="updatePassword" autocomplete="new-password" />

            <x-form.input name="password_confirmation" label="Confirm new password" type="password"
                          bag="updatePassword" autocomplete="new-password" />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-line px-5 py-3">
            @if (session('status') === 'password-updated')
                <p x-data="{ show: true }" x-show="show" x-transition
                   x-init="setTimeout(() => show = false, 2500)"
                   class="flex items-center gap-1.5 text-sm text-emerald-700">
                    <x-icon name="check" class="size-4" /> Password changed
                </p>
            @endif
            <x-button type="submit">Change password</x-button>
        </div>
    </form>
</section>
