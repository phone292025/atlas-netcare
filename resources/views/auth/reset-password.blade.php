<x-guest-layout>
    <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        {{-- The email arrives in the reset link, so it is prefilled. old() still
             wins if the form was rejected. --}}
        <x-form.input name="email" label="Email" type="email" required autofocus
                      autocomplete="username" :value="$request->email" />

        <x-form.input name="password" label="New password" type="password" required
                      autocomplete="new-password" />

        <x-form.input name="password_confirmation" label="Confirm new password"
                      type="password" required autocomplete="new-password" />

        <div class="flex justify-end pt-2">
            <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-700 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Reset password
            </button>
        </div>
    </form>
</x-guest-layout>
