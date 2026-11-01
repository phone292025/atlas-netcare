<?php

namespace App\Services\Incidents;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Writes an incident's timeline. The only writer of `incident_events`. */
class IncidentLog
{
    public function write(Incident $incident, string $type, string $message, ?User $user = null, ?Carbon $at = null): IncidentEvent
    {
        return $incident->events()->create([
            'type' => $type,
            'user_id' => $user?->id,
            'message' => $message,
            'happened_at' => $at ?? now(),
        ]);
    }
}
