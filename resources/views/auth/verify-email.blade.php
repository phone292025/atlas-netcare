<x-guest-layout>
    {{-- Copy deliberately does not say "thanks for signing up". There is no
         signup in Atlas NetCare — an administrator created this account
         (FR-105). See docs/04-open-questions.md Q6. --}}
    <p class="mb-4 text-sm text-slate-600">
        Before you start, please confirm your email address using the link we
        just sent you. If it has not arrived, we can send another.
    </p>

    @if (session('status') === 'verification-link-sent')
        <x-alert type="success" class="mb-4">
            A new verification link has been sent to your email address.
        </x-alert>
    @endif

    <div class="mt-4 flex items-center justify-between gap-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-700 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Resend verification email
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="rounded text-sm text-slate-600 underline hover:text-slate-900
                           focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                Sign out
            </button>
        </form>
    </div>
</x-guest-layout>
