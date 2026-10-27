<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of a batch from a probe agent (ADR-0017).
 *
 * A batch that breaks these is refused whole, with 422: it means the agent
 * itself is broken. A well-formed reading can still be refused on its own,
 * for its device or its time, by ReadingIngest.
 *
 * Every number has a range, so nothing an agent sends can overflow a column
 * or claim a loss of 300 per cent.
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
            'readings.*' => ['array:sequence,device,measured_at,reachable,latency_ms,packet_loss_pct,client_count,channel_util_pct,throughput_mbps'],
            'readings.*.sequence' => ['required', 'integer', 'min:1', 'max:9007199254740991', 'distinct'],
            'readings.*.device' => ['required', 'string', 'max:40'],
            'readings.*.measured_at' => ['required', 'date'],
            'readings.*.reachable' => ['required', 'boolean'],
            'readings.*.latency_ms' => ['nullable', 'numeric', 'min:0', 'max:65535'],
            'readings.*.packet_loss_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'readings.*.client_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'readings.*.channel_util_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'readings.*.throughput_mbps' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }
}
