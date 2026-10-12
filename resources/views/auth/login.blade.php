<x-guest-layout>
    <x-slot name="title">Sign in</x-slot>
    <x-slot name="heading">Welcome back</x-slot>
    <x-slot name="intro">Sign in with the account your IT office gave you.</x-slot>

    <x-auth-session-status class="mb-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <x-form.input name="email" label="Email" type="email" required
                      autofocus autocomplete="username" />

        <div>
            <x-form.input name="password" label="Password" type="password" required revealable
                          autocomplete="current-password" />
            {{-- FR-102. The proposal lists "recover password" for all three
                 roles, so this stays even though delivery depends on a mail
                 server the demonstration environment may not have. See
                 docs/02-open-questions.md Q11. --}}
            <a href="{{ route('password.request') }}" class="link mt-2 inline-block text-sm">Forgot your password?</a>
        </div>

        <label for="remember_me" class="flex items-center gap-2.5">
            <input id="remember_me" name="remember" type="checkbox" class="size-4 rounded">
            <span class="text-sm text-fg-muted">Keep me signed in on this device</span>
        </label>

        <button type="submit" class="btn btn-primary btn-lg w-full">
            Sign in
        </button>
    </form>
</x-guest-layout>
