<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

/**
 * Incidents are the network's state, which every role sees on the monitor
 * already. Anybody on call can acknowledge one: an assistant on shift is the
 * first person told. Raising a ticket from one is an engineer's decision.
 */
class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Incident $incident): bool
    {
        return $user->is_active;
    }

    public function acknowledge(User $user, Incident $incident): bool
    {
        return $user->is_active && ! $incident->isResolved();
    }

    public function raiseTicket(User $user, Incident $incident): bool
    {
        return ($user->isEngineer() || $user->isAdmin())
            && $incident->ticket_id === null
            && $incident->network_device_id !== null;
    }
}
