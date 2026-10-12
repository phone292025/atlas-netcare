<section class="grid gap-4 py-8 first:pt-0 md:grid-cols-[15rem_minmax(0,1fr)] md:gap-8">
    <header>
        <h3 class="text-[15px] font-semibold text-fg">Your details</h3>
        <p class="mt-1 text-sm text-fg-muted">Your name as colleagues see it, and the email you sign in with.</p>
    </header>

    {{-- Breeze's "resend verification email" form used to sit here. Email
         verification was never live in this system: User does not implement
         MustVerifyEmail and nothing carries the `verified` middleware, so the
         block below it could never render either. Both are gone.
         See routes/auth.php. --}}

    <form method="post" action="{{ route('profile.update') }}" class="rounded-xl border border-line bg-surface shadow-xs">
        @csrf
        @method('patch')

        <div class="space-y-4 p-5">
            <x-form.input name="name" label="Name" required
                          autocomplete="name" :value="$user->name" />

            <x-form.input name="email" label="Email" type="email" required
                          autocomplete="username" :value="$user->email" />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-line px-5 py-3">
            <x-button type="submit">Save details</x-button>
        </div>
    </form>
</section>
