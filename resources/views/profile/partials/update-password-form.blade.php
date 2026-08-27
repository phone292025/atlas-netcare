<section>
    <header>
        <h2 class="text-lg font-medium text-slate-900">Update Password</h2>

        <p class="mt-1 text-sm text-slate-600">
            Ensure your account is using a long, random password to stay secure.
        </p>
    </header>

    {{-- Two forms share this page, so this one validates into its own named
         error bag. Without the `bag` prop the fields would look for their
         errors in the default bag and silently show nothing. --}}
    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        @method('put')

        <x-form.input name="current_password" label="Current password" type="password"
                      bag="updatePassword" autocomplete="current-password" />

        <x-form.input name="password" label="New password" type="password"
                      bag="updatePassword" autocomplete="new-password" />

        <x-form.input name="password_confirmation" label="Confirm new password" type="password"
                      bag="updatePassword" autocomplete="new-password" />

        <div class="flex items-center gap-4 pt-2">
            <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-700 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Save
            </button>

            @if (session('status') === 'password-updated')
                <p x-data="{ show: true }"
                   x-show="show"
                   x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-sm text-slate-600">Saved.</p>
            @endif
        </div>
    </form>
</section>
