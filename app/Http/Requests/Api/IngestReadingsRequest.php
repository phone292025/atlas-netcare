<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of a batch from a probe agent (ADR-0017).
 *
 * A batch that breaks these is refused whole, with 422, and the agent keeps
 * every reading and waits: what is wrong is the request or the agent, a
 * version string too long, more readings than Atlas takes, a reading with
 * no sequence, and sending the readings again one by one would not help.
 *
 * Each reading's own values are judged by ReadingIngest instead, and a bad
 * one is rejected on its own, in the 200 answer, with its reason. One
 * reading with a latency over the column's limit must not hold up the 499
 * beside it.
 */
class IngestReadingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sent_at' => ['required', 'date'],
            'agent_version' => ['nullable', 'string', 'max:40'],
            'readings' => ['present', 'array', 'list', 'max:'.(int) config('atlas.ingest.max_readings')],
            'readings.*' => ['array'],
            // The agent numbers every reading itself; a missing or repeated
            // sequence is the agent broken, and the answer is by sequence.
            'readings.*.sequence' => ['required', 'integer', 'min:1', 'max:9007199254740991', 'distinct'],
        ];
    }
}
