<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registering a probe agent. Only its name comes from the form: the token is
 * generated, never typed (ADR-0017).
 */
class AgentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Retired agents keep their names, so a name is never reused for a
            // different machine in the history.
            'name' => ['required', 'string', 'max:120', Rule::unique('agents', 'name')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'An agent already has that name, or had it before it was retired.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
