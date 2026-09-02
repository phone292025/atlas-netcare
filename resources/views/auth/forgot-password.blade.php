<x-guest-layout>
    <p class="mb-4 text-sm text-slate-600">
        Forgot your password? Tell us your email address and we will send you a
        link to choose a new one.
    </p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    {{-- Say what will actually happen, because two different configurations
         make this screen lie in the same way.

         With MAIL_MAILER=log nothing is sent anywhere: Laravel writes the
         whole message, reset token included, into storage/logs/laravel.log.
         With smtp and no app password the send throws instead. Laravel's
         success notice above says "We have emailed your password reset link"
         in both cases. Seven links accumulated in that log before anybody
         noticed, because the screen said otherwise.

         Shown only when debug is on, so it can never appear on a configured
         deployment. A feature that quietly does nothing is worse than one
         that admits it.

         @see docs/02-open-questions.md Q11 --}}
    @if (config('app.debug') && ! config('atlas.mail_configured'))
        <x-alert type="warning" class="mb-4" title="No mail server is configured">
            @if (config('mail.default') === 'log')
                Nothing will be sent. Laravel writes the reset link into
                <code class="font-mono text-xs">storage/logs/laravel.log</code>
                instead of delivering it.
            @else
                <code class="font-mono text-xs">MAIL_PASSWORD</code> is empty, so
                the send will fail. Paste a Google app password into
                <code class="font-mono text-xs">.env</code>, then check it with
                <code class="font-mono text-xs">php artisan atlas:mail-test</code>.
            @endif
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
