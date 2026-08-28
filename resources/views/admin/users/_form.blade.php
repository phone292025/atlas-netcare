{{-- Shared by create and edit, so the two screens cannot drift apart.
     Included with ['user' => User|null, 'roles' => array]. --}}
<div class="space-y-4">
    <x-form.input name="name" label="Full name" required :value="$user?->name" autofocus />

    <x-form.input name="email" label="Email" type="email" required :value="$user?->email"
                  help="This is what they sign in with." />

    <x-form.input name="tp_number" label="TP number" :value="$user?->tp_number"
                  help="Students only. Leave blank for staff engineers." />

    <x-form.input name="phone" label="Phone" :value="$user?->phone" />

    <x-form.select name="role" label="Role" required
                   :options="$roles"
                   :selected="$user?->role?->value"
                   placeholder="Choose a role"
                   help="Decides which parts of the system they can reach." />

    <x-form.input name="password" label="{{ $user ? 'New password' : 'Password' }}" type="password"
                  :required="! $user"
                  help="{{ $user
                      ? 'Leave blank to keep the current password.'
                      : 'They will be asked to change this the first time they sign in.' }}" />

    <x-form.input name="password_confirmation" label="Confirm password" type="password"
                  :required="! $user" />
</div>
