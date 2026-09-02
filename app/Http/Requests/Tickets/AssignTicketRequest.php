<?php

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Handing a ticket to an engineer, or taking the name off it (FR-307).
 *
 * @see App\Services\Tickets\TicketService::assign()
 */
class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assign', Ticket::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Nullable is meaningful: it unassigns, which is how an engineer
            // going on leave puts work back in the pool rather than picking a
            // colleague for it.
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'assigned_to.exists' => 'That person does not have an account.',
        ];
    }

    /**
     * The engineer to hand it to, or null to unassign.
     *
     * Whether they may actually hold work is TicketService's decision, not
     * this class's — a deactivated account and a non-engineer are both
     * refusals that belong with the rest of the rules.
     */
    public function engineer(): ?User
    {
        return $this->filled('assigned_to')
            ? User::find($this->integer('assigned_to'))
            : null;
    }
}
