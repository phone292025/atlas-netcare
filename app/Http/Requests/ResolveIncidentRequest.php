<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Resolving a degraded or flapping incident by hand: why, for the timeline. */
class ResolveIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resolve', $this->route('incident'));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
