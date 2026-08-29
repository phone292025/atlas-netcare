<x-guest-layout>
    <p class="mb-4 text-sm text-slate-600">
        Forgot your password? Tell us your email address and we will send you a
        link to choose a new one.
    </p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    {{-- Say where the link actually went.

         With MAIL_MAILER=log nothing is sent anywhere: Laravel writes the
         message into storage/logs/laravel.log. The success notice above still
         reads "We have emailed your password reset link", which is Laravel's
         wording and is a lie in this configuration. Seven links accumulated in
         that log before anybody noticed, because the screen said otherwise.

         Shown only when the mailer is `log` AND debug is on, so it can never
         appear on a configured deployment. A feature that quietly does nothing
         is worse than one that admits it.

         @see docs/04-open-questions.md Q11 --}}
    @if (config('mail.default') === 'log' && config('app.debug'))
        <x-alert type="warning" class="mb-4" title="No mail server is configured">
            Nothing was sent. Laravel wrote the reset link into
            <code class="font-mono text-xs">storage/logs/laravel.log</code> instead.
            The demo accounts also use <code class="font-mono text-xs">@apu.test</code>,
            a reserved domain that can never receive mail.
        </x-alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-form.input name="email" label="Email" type="email" required autofocus />

        <div class="flex justify-end pt-2">
            <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-700 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Email password reset link
            </button>
        </div>
    </form>
</x-guest-layout>
