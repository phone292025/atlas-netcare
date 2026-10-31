<x-guest-layout>
    <x-slot:heading>Choose a new password</x-slot:heading>
    <x-slot:intro>A long one you do not use anywhere else. A phrase of a few words works well.</x-slot:intro>

    {{-- This screen is reachable while signed in, unlike the rest of the
         signed-out shell. The link arrives by email and nobody controls which
         browser opens it, so refusing an authenticated visitor just makes a
         working link look broken.

         When there is a session, say so. Two people have to be able to tell
         them apart: somebody resetting their own password on a device they
         are still signed in on, and somebody who has opened a colleague's
         link. The form below changes the password of the address in the box,
         not the account in the corner of the screen.

         @see routes/auth.php for why `guest` is not on this route --}}
    @auth
        <x-alert type="warning" class="mb-4" :title="'You are signed in as '.auth()->user()->name">
            This resets the password for the address below, which is the one
            the link was sent to. Everyone signed in to that account, on every
            device, is signed out.
        </x-alert>
    @endauth

    <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        {{-- The email arrives in the reset link, so it is prefilled. old() still
             wins if the form was rejected. --}}
        <x-form.input name="email" label="Email" type="email" required autofocus
                      autocomplete="username" :value="$request->email" />

        <x-form.input name="password" label="New password" type="password" required revealable
                      autocomplete="new-password" />

        <x-form.input name="password_confirmation" label="Confirm new password"
                      type="password" required autocomplete="new-password" />

        <x-button type="submit" size="lg" class="w-full">Reset password</x-button>
    </form>
</x-guest-layout>
