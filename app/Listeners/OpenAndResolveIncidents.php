<?php

namespace App\Listeners;

use App\Events\AgentSilenceChanged;
use App\Events\DeviceStatusesChanged;
use App\Services\Incidents\IncidentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

/**
 * Telemetry's events, turned into incidents (ADR-0014, roadmap 2.1).
 *
 * On the queue, so a slow reaction never delays the readings, with retries.
 * Every listener for Telemetry's events is in this class: the docs promise
 * one place to read the whole reaction to a fault. If every retry fails, the
 * incident sweep (`atlas:watch-incidents`, every minute) finds the device's
 * status and opens the incident anyway.
 */
class OpenAndResolveIncidents implements ShouldQueue
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(private readonly IncidentService $incidents) {}

    public function handleStatuses(DeviceStatusesChanged $event): void
    {
        $this->incidents->statusesChanged($event->changes, Carbon::parse($event->at));
    }

    public function handleSilence(AgentSilenceChanged $event): void
    {
        $this->incidents->agentSilenceChanged($event->agentId, $event->silent, now());
    }
}
