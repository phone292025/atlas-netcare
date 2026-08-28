<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a ticket to another state.
 *
 * This validates the SHAPE of the request. Whether the move is legal is
 * TicketService's job, and whether this person may make it is TicketPolicy's —
 * three separate questions, deliberately answered in three places.
 */
class TransitionTicketRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TicketStatus::class)],

            /*
             * Required when resolving, and only then. "No silent closures" —
             * a ticket that went away without anybody writing down what was
             * done teaches nobody anything, and M7 has nothing to learn from.
             */
            'resolution_notes' => [
                Rule::requiredIf(fn () => $this->input('status') === TicketStatus::Resolved->value),
                'nullable', 'string', 'max:4000',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'resolution_notes.required' => 'Say what you did to fix it before resolving.',
        ];
    }

    public function target(): TicketStatus
    {
        return TicketStatus::from($this->validated('status'));
    }
}
