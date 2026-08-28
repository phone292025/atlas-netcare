<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One request class for create and update.
 *
 * The rules differ only in whether the unique check ignores the record being
 * edited, which is not enough difference to justify two files that then drift.
 *
 * Authorisation is the `role:admin` middleware on the route group. There is no
 * per-record rule here — unlike M1, where an administrator must not be able to
 * deactivate themselves.
 */
class LocationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('locations', 'code')->ignore($this->route('location')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['it_lab', 'library', 'cafeteria', 'other'])],
            'floor' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another location already uses that code.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Codes are read off door signage and typed in a hurry. Normalising
        // here means "l6-it-01" and "L6-IT-01" cannot both exist.
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim($this->string('code')))]);
        }
    }
}
