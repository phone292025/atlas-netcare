<x-app-layout>
    <x-slot name="title">Profile</x-slot>

    <div class="max-w-3xl space-y-6">
        <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
            <div class="max-w-xl">
                @include('profile.partials.update-profile-information-form')
            </div>
        </div>

        <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
            <div class="max-w-xl">
                @include('profile.partials.update-password-form')
            </div>
        </div>

        {{-- Account deletion is deliberately absent. Atlas NetCare deactivates
             accounts rather than deleting them, so audit history survives
             (NFR-06). Only an administrator can do it.
             @see docs/modules/M1-identity-and-audit.md --}}
    </div>
</x-app-layout>
