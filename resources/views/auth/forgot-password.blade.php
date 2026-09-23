<x-guest-layout>
    <p class="mb-4 text-sm text-slate-600">
        Forgot your password? Tell us your email address and we will send you a
        link to choose a new one.
    </p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    {{-- Say what will actually happen when this machine cannot send mail:
         with MAIL_MAILER=log the link lands in storage/logs/laravel.log, and
         with smtp but no app password the send fails. Only while debug is on,
         so it never appears on a configured deployment.

         @see docs/02-open-questions.md Q11 --}}
    @if (config('app.debug') && ! config('atlas.mail_configured'))
        <x-alert type="warning" class="mb-4" title="No mail server is configured">
            @if (config('mail.default') === 'log')
                Nothing will be sent. Laravel writes the reset link into
                <code class="font-mono text-xs">storage/logs/laravel.log</code>
                instead of delivering it.
                <span class="mt-1 block">
                    The link still works. Run
                    <code class="font-mono text-xs">php artisan atlas:reset-link</code>
                    to print it.
                </span>
            @else
                <code class="font-mono text-xs">MAIL_PASSWORD</code> is empty, so
                the send will fail. Paste a Google app password into
                <code class="font-mono text-xs">.env</code>, then check it with
                <code class="font-mono text-xs">php artisan atlas:mail-test</code>.
            @endif
        </x-alert>
    @endif

    {{-- The link itself, when this machine cannot send mail.

         Delivery needs a Google app password, which is a key to a personal
         account and can never be committed, so nobody cloning this project can
         send email. Without this a reviewer reaches a dead end and FR-102 looks
         broken when it is only undelivered.

         The token behind it is real, single-use and still expires. Only where
         the link appears changes. AppServiceProvider flashes it, and only when
         the environment is local, debug is on, and no mailer is configured.

         @see docs/02-open-questions.md Q11 --}}
    @if (session('reset_link'))
        <x-alert type="info" class="mb-4" title="Here is the reset link">
            <p class="mb-2">
                No mail was sent, so the link is here instead. It expires like any other.
            </p>

            <a href="{{ session('reset_link') }}"
               class="break-all font-mono text-xs text-brand-700 underline hover:text-brand-900">
                {{ session('reset_link') }}
            </a>
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
