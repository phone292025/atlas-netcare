<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Editing an account.
 *
 * The password is optional: leaving it blank keeps the existing one, which is
 * what an administrator correcting a typo in a name expects.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)->withoutTrashed()],
            'tp_number' => ['nullable', 'string', 'max:20', Rule::unique('users', 'tp_number')->ignore($user)->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }
}
