<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

/**
 * Who may do what to a ticket.
 *
 * TicketService says which transitions are LEGAL; this says who is allowed to
 * make them. Both have to agree before a ticket moves, and they answer
 * genuinely different questions — "can a resolved ticket be closed?" is the
 * machine's business, "may this assistant close it?" is this file's.
 *
 * @see App\Services\Tickets\TicketService
 * @see docs/05-architecture.md — Authorisation
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * An assistant sees the tickets they reported. Engineers and admins see
     * everything — the queue is the whole point of the engineer's screen.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->isEngineer() || $user->isAdmin()) {
            return true;
        }

        return $ticket->reported_by === $user->id;
    }

    public function create(User $user): bool
    {
        // Engineers raise tickets too — they find faults while working on
        // something else, and making them ask an assistant to file it is how
        // faults stop being recorded.
        return $user->isAssistant() || $user->isEngineer() || $user->isAdmin();
    }

    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /** Only staff may leave a comment the reporter cannot see. */
    public function commentInternally(User $user): bool
    {
        return $user->isEngineer() || $user->isAdmin();
    }

    /** Taking ownership of a ticket is engineer work. */
    public function acknowledge(User $user, Ticket $ticket): bool
    {
        return $user->isEngineer() || $user->isAdmin();
    }

    /**
     * Only the engineer who owns it may work it or resolve it.
     *
     * Otherwise two engineers can both be "working on" one ticket without
     * either knowing, which is precisely the ad-hoc coordination this module
     * exists to replace. An admin can always step in.
     */
    public function work(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isEngineer() && $ticket->assigned_to === $user->id;
    }

    public function resolve(User $user, Ticket $ticket): bool
    {
        return $this->work($user, $ticket);
    }

    /**
     * Closing: the reporter or an engineer, per Q3's transition table.
     *
     * The team's open sub-question was whether closure should require the
     * reporter to confirm. The tabled answer is "Reporter or engineer", so an
     * engineer may close their own resolution — with the reporter able to
     * reopen it if it was not actually fixed. That pairing is what makes
     * engineer-closes safe: the reporter keeps the last word.
     */
    public function close(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin()
            || $user->isEngineer()
            || $ticket->reported_by === $user->id;
    }

    /**
     * Reopening belongs to the person who reported it.
     *
     * They are the only one who knows whether the fault is actually gone. An
     * admin can too, for the case where the reporter has left.
     */
    public function reopen(User $user, Ticket $ticket): bool
    {
        return $ticket->status === TicketStatus::Resolved
            && ($ticket->reported_by === $user->id || $user->isAdmin());
    }

    public function assign(User $user): bool
    {
        return $user->isEngineer() || $user->isAdmin();
    }
}
