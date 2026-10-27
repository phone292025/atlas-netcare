<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IngestReadingsRequest;
use App\Models\Agent;
use App\Services\Telemetry\AgentService;
use App\Services\Telemetry\ReadingIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * POST /api/v1/readings: a batch of readings from a probe agent (ADR-0017).
 *
 * The agent was let in by AuthenticateAgent. Every batch counts as a
 * heartbeat, an empty one included, and carries the agent's clock, which is
 * compared with Atlas's here.
 *
 * Answers 200 with what happened to each reading, by sequence. The agent
 * drops all of them from its buffer: stored and duplicate ones are safe, and
 * rejected ones would never be stored.
 */
class ReadingIngestController extends Controller
{
    public function __invoke(IngestReadingsRequest $request, ReadingIngest $ingest, AgentService $agents): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->attributes->get('agent');
        $receivedAt = now();

        // Positive when the agent's clock is ahead. Network delay makes it a
        // little high, which is why only seconds of difference are flagged.
        $sentAt = Carbon::parse($request->validated('sent_at'));
        $offsetMs = (int) round($sentAt->getPreciseTimestamp(3) - $receivedAt->getPreciseTimestamp(3));

        $agents->recordContact($agent, $request->ip(), $request->validated('agent_version'), $offsetMs);

        $outcome = $ingest->ingest($agent, $request->validated('readings'), $receivedAt);

        return response()->json([
            ...$outcome,
            'received_at' => $receivedAt->toIso8601ZuluString(),
            'clock_offset_ms' => $offsetMs,
        ]);
    }
}
