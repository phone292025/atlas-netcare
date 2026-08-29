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

        <div class="flex items-center justify-end gap-4 pt-2">
            {{-- FR-102. The proposal lists "recover password" for all three
                 roles, so this stays even though delivery depends on a mail
                 server the demonstration environment does not have. See
                 docs/04-open-questions.md Q11. --}}
            <a href="{{ route('password.request') }}"
               class="rounded text-sm text-slate-600 underline hover:text-slate-900
                      focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                Forgot your password?
            </a>

            <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-700 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Log in
            </button>
        </div>
    </form>
</x-guest-layout>
