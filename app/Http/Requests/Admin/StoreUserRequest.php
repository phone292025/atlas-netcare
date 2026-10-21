<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creating an account. There is no self-registration, so this is the only way
 * a user enters the system (FR-105, FR-401).
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * Check uniqueness against addresses in their stored form, so an address
     * that differs only in case counts as taken. See User::normalizeEmail().
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => User::normalizeEmail($this->string('email')->toString())]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'tp_number' => ['nullable', 'string', 'max:20', Rule::unique('users', 'tp_number')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account already uses that email address.',
            'tp_number.unique' => 'That TP number belongs to another account.',
        ];
    }
}
