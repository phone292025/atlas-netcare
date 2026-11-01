<?php

namespace App\Services\Incidents;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;

/**
 * The ticket for an incident: at most one (roadmap 2.4). It replaces the
 * collector's old auto-ticket, which raised one per access point and could
 * raise twelve for one broken switch. A device that fails again while its
 * ticket is still open gets that ticket, not a second one (FR-503).
 */
class IncidentTickets
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly IncidentLog $log,
    ) {}

    /**
     * Raise the incident's ticket, or adopt the device's open one. Raised by
     * `$by`, or automatically when null: an offline incident does it on
     * opening, and the first administrator stands in as the reporter, since
     * `reported_by` is not nullable.
     */
    public function raiseFor(Incident $incident, ?User $by = null, int $grouped = 0): ?Ticket
    {
        $device = $incident->device;

        if (! $device || $incident->ticket_id !== null || (! $by && ! $incident->rule->raisesTicket())) {
            return null;
        }

        // A ticket still open for this device, raised by hand, by an earlier
        // outage or before incidents existed, is the work for this fault too.
        $existing = Ticket::query()->outstanding()
            ->where('ticketable_type', $device->getMorphClass())
            ->where('ticketable_id', $device->id)
            ->oldest('opened_at')
            ->first();

        if ($existing) {
            $incident->forceFill(['ticket_id' => $existing->id])->save();
            $this->log->write($incident, 'ticket', "linked it to {$existing->ticket_number}, already open for {$device->code}.", $by);

            return $existing;
        }

        $reporter = $by ?? User::query()->active()->role(UserRole::Admin)->orderBy('id')->first();

        if (! $reporter) {
            $this->log->write($incident, 'note', 'raised no ticket: there is no administrator to raise it in the system\'s name.');

            return null;
        }

        $behind = $grouped > 0 ? " {$grouped} more ".str('incident')->plural($grouped).' behind it were grouped into this one.' : '';

        // An absolute campus time, because the description is saved: "2 hours
        // ago" would still say so next week.
        $ticket = $this->tickets->open([
            'title' => $incident->title,
            'description' => ($by ? "Raised by {$by->name} from incident #{$incident->id}" : "Raised automatically from incident #{$incident->id}")
                .": {$incident->rule->label()} on {$device->code}"
                .($device->location ? " in {$device->location->label()}" : '')
                .'. Last seen '.($device->last_seen_at?->campus()->format('j M Y, H:i') ?? 'never').'.'.$behind,
            'category' => TicketCategory::Network->value,
            'priority' => ($by ? TicketPriority::Medium : TicketPriority::Critical)->value,
            'location_id' => $device->location_id,
        ], $reporter, $device, isDemo: (bool) $device->is_demo);

        $incident->forceFill(['ticket_id' => $ticket->id])->save();
        $this->log->write($incident, 'ticket', "raised {$ticket->ticket_number}.", $by);

        return $ticket;
    }
}
