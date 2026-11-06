<?php

namespace App\Console\Commands;

use App\Services\Incidents\IncidentAlerts;
use App\Services\Incidents\IncidentService;
use App\Services\Incidents\OnCall;
use Illuminate\Console\Command;

/**
 * The incident sweep and the on-call escalation, every minute.
 *
 * The sweep compares the incidents with every device's and agent's current
 * status and fixes any difference, so an event lost to a crashed worker
 * delays an incident by a minute and never loses it. Then every incident
 * nobody has acknowledged in time goes to the next people on call. Last,
 * every alert still owed whose job was lost is queued again.
 *
 * @see docs/modules/M8-incidents.md
 */
class WatchIncidents extends Command
{
    protected $signature = 'atlas:watch-incidents';

    protected $description = 'Open and resolve incidents from current statuses, and escalate unacknowledged ones';

    public function handle(IncidentService $incidents, OnCall $onCall, IncidentAlerts $alerts): int
    {
        $swept = $incidents->sweep();
        $escalated = $onCall->escalateDue();
        $requeued = $alerts->requeueStalled();

        if ($swept['opened'] + $swept['resolved'] + $escalated + $requeued > 0) {
            $this->line("Opened {$swept['opened']}, resolved {$swept['resolved']}, escalated {$escalated}, alerts queued again {$requeued}.");
        }

        return self::SUCCESS;
    }
}
