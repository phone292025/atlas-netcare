<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Models\AccessPoint;
use App\Models\LabPc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a ticket.
 *
 * `status`, `ticket_number` and every lifecycle timestamp are absent on
 * purpose — they belong to TicketService. A request that accepted `status`
 * would let somebody open a ticket already closed.
 *
 * @see docs/03-requirements.md FR-206, FR-207
 */
class StoreTicketRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:4000'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],

            /*
             * The asset is optional: "no network anywhere on Level 6" is a
             * real fault that belongs to a place rather than a machine. But
             * the TYPE is constrained to the two things that can carry a
             * ticket — an unchecked morph type is how a polymorphic column
             * ends up pointing at a User.
             */
            'ticketable_type' => ['nullable', 'required_with:ticketable_id', Rule::in(['lab_pc', 'access_point'])],
            'ticketable_id' => ['nullable', 'required_with:ticketable_type', 'integer'],

            'location_id' => ['nullable', 'exists:locations,id'],
            'inspection_id' => ['nullable', 'exists:inspections,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Give the fault a short title an engineer can scan.',
            'description.required' => 'Describe what is wrong.',
            'ticketable_id.required_with' => 'Choose which machine this is about.',
        ];
    }

    /**
     * A type with no id means "not about a specific machine".
     *
     * The form posts a fixed `ticketable_type` alongside an optional machine
     * picker, so leaving the picker blank would otherwise trip
     * `required_with` and reject a fault that legitimately belongs to a room
     * rather than a box — "no network anywhere on Level 6". Dropping the
     * orphan type here is more robust than making the view conditionally
     * render the hidden field, because it also covers a hand-built request.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('ticketable_id')) {
            $this->merge(['ticketable_type' => null, 'ticketable_id' => null]);
        }
    }

    /**
     * The asset this ticket is about, already loaded — or null.
     *
     * The form posts a short key rather than a class name. Accepting
     * `ticketable_type` as a raw FQCN from a request would let a crafted POST
     * attach a ticket to any model in the system.
     */
    public function subject(): ?object
    {
        if (! $this->filled('ticketable_type') || ! $this->filled('ticketable_id')) {
            return null;
        }

        return match ($this->validated('ticketable_type')) {
            'lab_pc' => LabPc::withTrashed()->find($this->validated('ticketable_id')),
            'access_point' => AccessPoint::withTrashed()->find($this->validated('ticketable_id')),
            default => null,
        };
    }

    /**
     * Only the columns the model may be filled with.
     *
     * @return array<string, mixed>
     */
    public function ticketAttributes(): array
    {
        return $this->safe()->only([
            'title', 'description', 'category', 'priority', 'location_id', 'inspection_id',
        ]);
    }
}
