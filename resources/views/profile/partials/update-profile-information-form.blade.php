<section>
    <header>
        <h2 class="text-lg font-medium text-slate-900">Profile Information</h2>

        <p class="mt-1 text-sm text-slate-600">
            Update your account's profile information and email address.
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-4">
        @csrf
        @method('patch')

        <x-form.input name="name" label="Name" required autofocus
                      autocomplete="name" :value="$user->name" />

        <x-form.input name="email" label="Email" type="email" required
                      autocomplete="username" :value="$user->email" />

        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
            <div class="text-sm">
                <p class="text-slate-800">
                    Your email address is unverified.

                    <button form="send-verification"
                            class="rounded underline text-slate-600 hover:text-slate-900
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                        Click here to re-send the verification email.
                    </button>
                </p>

                @if (session('status') === 'verification-link-sent')
                    <p class="mt-2 text-sm font-medium text-emerald-600">
                        A new verification link has been sent to your email address.
                    </p>
                @endif
            </div>
        @endif

        <div class="flex items-center gap-4 pt-2">
            <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-700 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Save
            </button>

            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }"
                   x-show="show"
                   x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-sm text-slate-600">Saved.</p>
            @endif
        </div>
    </form>
</section>
