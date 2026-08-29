<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-form.input name="email" label="Email" type="email" required
                      autofocus autocomplete="username" />

        <x-form.input name="password" label="Password" type="password" required
                      autocomplete="current-password" />

        <label for="remember_me" class="flex items-center gap-2">
            <input id="remember_me" name="remember" type="checkbox"
                   class="rounded border-slate-300 text-brand-600 shadow-sm focus:ring-brand-500">
            <span class="text-sm text-slate-600">Remember me</span>
        </label>

        {{-- No "forgot your password?" link, and no reset flow behind it.

             Breeze's version emailed a link, and every account here uses a
             reserved domain that can never receive mail, so the screen claimed
             to have sent an email while writing the link into a log file. The
             recovery path is an administrator setting a new password, which is
             what the note under this form says. --}}
        <div class="flex items-center justify-end gap-4 pt-2">
            <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-700 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Log in
            </button>
        </div>
    </form>
</x-guest-layout>
