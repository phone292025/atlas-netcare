<x-guest-layout>
    <p class="mb-4 text-sm text-slate-600">
        This is a secure area of the application. Please confirm your password
        before continuing.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
        @csrf

        <x-form.input name="password" label="Password" type="password" required
                      autocomplete="current-password" />

        <div class="flex justify-end pt-2">
            <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-700 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Confirm
            </button>
        </div>
    </form>
</x-guest-layout>
