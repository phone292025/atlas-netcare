<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\IncidentAlert;
use App\Models\User;

/**
 * Incidents are the network's state, which every role sees on the monitor
 * already. Acknowledging stops the escalation, so it is for the people whose
 * job the incident is: engineers and administrators, and an assistant it
 * was alerted to. Raising a ticket from one, or closing a degraded one by
 * hand, is an engineer's decision.
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
        if (! $user->is_active || $incident->isResolved()) {
            return false;
        }

        return $user->isEngineer() || $user->isAdmin()
            || IncidentAlert::query()->where('incident_id', $incident->id)->where('user_id', $user->id)->exists();
    }

    public function resolve(User $user, Incident $incident): bool
    {
        return ($user->isEngineer() || $user->isAdmin())
            && ! $incident->isResolved()
            && $incident->rule->canBeResolvedByHand();
    }

    public function raiseTicket(User $user, Incident $incident): bool
    {
        return ($user->isEngineer() || $user->isAdmin())
            && $incident->ticket_id === null
            && $incident->network_device_id !== null;
    }
}
