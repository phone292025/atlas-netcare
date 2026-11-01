<?php

namespace App\Events;

use App\Enums\NetworkStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Some devices' statuses changed (ADR-0014, roadmap 2.1).
 *
 * Telemetry publishes it, once per round or batch, and knows nothing about
 * who listens. One event for the whole batch, so a listener can see a switch
 * and the access points behind it fail together and open one incident.
 *
 * Dispatched after the transaction that stored the readings commits: a
 * listener never acts on a status that was rolled back.
 *
 * @see App\Listeners\OpenAndResolveIncidents
 */
final class DeviceStatusesChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{device_id: int, from: string, to: string}>  $changes  in the order they happened
     */
    public function __construct(
        public readonly array $changes,
        public readonly string $at,
    ) {}

    /**
     * @param  iterable<array{device: object, from: NetworkStatus, to: NetworkStatus}>  $transitions
     */
    public static function fromTransitions(iterable $transitions): self
    {
        $changes = [];

        foreach ($transitions as $change) {
            $changes[] = ['device_id' => (int) $change['device']->id, 'from' => $change['from']->value, 'to' => $change['to']->value];
        }

        return new self($changes, now()->toIso8601ZuluString('microsecond'));
    }
}
