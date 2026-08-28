<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A recurring weekly slot.
 */
class ShiftTemplateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // 0 = Sunday, matching Carbon::dayOfWeek.
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            // A slot that ends before it starts would generate shifts nobody
            // can ever check into — canCheckInNow() tests a window that has
            // already closed.
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'location_id' => ['required', 'exists:locations,id'],
            'required_assistants' => ['required', 'integer', 'min:1', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'end_time.after' => 'A shift has to end after it starts.',
        ];
    }
}
