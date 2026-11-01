<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agent went silent, or was heard from again after a silence (roadmap
 * 2.1). Telemetry publishes it once per silence and once per return.
 *
 * @see App\Listeners\OpenAndResolveIncidents
 */
final class AgentSilenceChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $agentId,
        public readonly bool $silent,
    ) {}
}
