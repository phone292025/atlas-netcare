<x-guest-layout>
    <x-slot:heading>Reset your password</x-slot:heading>
    <x-slot:intro>Forgot your password? Enter the email you sign in with and we will send you a link to choose a new one.</x-slot:intro>

    {{-- With no mail server the usual "a reset link is on its way" sat just
         above "Nothing will be sent", which contradicted itself. Only on a
         development install: a configured one never shows the warning. --}}
    <x-auth-session-status class="mb-4" :status="session('status') && \App\Providers\AppServiceProvider::resetLinkOnScreen(request())
        ? 'If that address has an account, its reset link is below. Nothing is emailed until a mail server is set up.'
        : session('status')" />

    {{-- Say what will actually happen when this machine cannot send mail:
         with MAIL_MAILER=log the link lands in the app's log, and with smtp
         but no password the send fails. Where the log is depends on the
         channel: the Docker stack sends it to the container's output
         (LOG_CHANNEL=stderr), where the file and atlas:reset-link find
         nothing. Only while debug is on, so it never appears on a configured
         deployment.

         @see docs/02-open-questions.md Q11 --}}
    @if (config('app.debug') && ! config('atlas.mail_configured'))
        <x-alert type="warning" class="mb-4" title="No mail server is configured">
            @if (config('mail.default') === 'log' && config('logging.default') === 'stderr')
                Nothing will be sent. Laravel writes the reset link into the
                app's log, which here is the container's output, instead of
                delivering it.
                <span class="mt-1 block">
                    The link still works. Run
                    <code class="font-mono text-xs">docker compose logs app | grep reset-password</code>
                    to find it.
                </span>
            @elseif (config('mail.default') === 'log')
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
                the send will fail. Set your mail provider's password in
                <code class="font-mono text-xs">.env</code>, then check it with
                <code class="font-mono text-xs">php artisan atlas:mail-test</code>.
            @endif
        </x-alert>
    @endif

    {{-- The link itself, when this machine cannot send mail.

         Delivery needs a mail account, whose password can never be
         committed, so a fresh clone can't send email. Without this a reviewer
         reaches a dead end and FR-102 looks broken when it is only
         undelivered.

         The token behind it is real, single-use and still expires. Only where
         the link appears changes. AppServiceProvider flashes it, and only when
         the environment is local, debug is on, no mailer is configured, and
         the request comes from this machine (App\Support\ResetLinkOnScreen).

         @see docs/02-open-questions.md Q11 --}}
    @if (session('reset_link'))
        <x-alert type="info" class="mb-4" title="Here is the reset link">
            <p class="mb-2">
                No mail was sent, so the link is here instead. It expires like any other.
            </p>

            <a href="{{ session('reset_link') }}"
               class="link break-all font-mono text-xs">
                {{ session('reset_link') }}
            </a>
        </x-alert>
    @endif
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-form.input name="email" label="Email" type="email" required autofocus autocomplete="username" />

        <x-button type="submit" size="lg" class="w-full">Email me a reset link</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-fg-muted">
        Remembered it? <a href="{{ route('login') }}" class="link font-medium">Back to sign in</a>
    </p>
</x-guest-layout>
