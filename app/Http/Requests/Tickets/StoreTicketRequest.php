<?php

namespace App\Http\Requests\Tickets;

use App\Enums\DeviceStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Models\AccessPoint;
use App\Models\LabPc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * The asset has to exist, and has to still be in service.
     *
     * `exists:` cannot express this on its own, because which table to look in
     * depends on `ticketable_type`. Without it, `ticketable_id` was validated
     * as nothing more than an integer: a request naming lab PC 999999 passed,
     * `subject()` then returned null, and the ticket was created SILENTLY
     * DETACHED. The reporter believed they had attached a machine and the
     * engineer saw a blank asset column.
     *
     * Retired and removed assets are refused as well. A ticket is a request to
     * go and fix something; raising one against a machine that has been taken
     * out of service is not a fault report, it is a mistake — and the ranking,
     * the reports and the risk model all exclude retired kit, so the ticket
     * would sit in the queue attached to something nothing else acknowledges.
     * Their existing tickets stay readable: this only governs new ones.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('ticketable_type') || ! $this->filled('ticketable_id')) {
                return;
            }

            $id = (int) $this->input('ticketable_id');

            $asset = match ($this->input('ticketable_type')) {
                'lab_pc' => LabPc::find($id),
                'access_point' => AccessPoint::find($id),
                default => null,
            };

            if (! $asset) {
                $validator->errors()->add(
                    'ticketable_id',
                    'That asset does not exist, or has been removed from service.',
                );

                return;
            }

            if ($asset instanceof LabPc && $asset->status === DeviceStatus::Retired) {
                $validator->errors()->add(
                    'ticketable_id',
                    'That machine has been retired, so a fault cannot be raised against it.',
                );
            }
        });
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
