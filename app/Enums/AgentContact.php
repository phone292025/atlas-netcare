<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * Whether a probe agent is being heard from (ADR-0017).
 *
 * Derived from `agents.last_seen_at` and `ingest.silent_after_minutes`, never
 * stored: it changes with the clock, not with a write.
 */
enum AgentContact: string implements HasLabel, HasTone
{
    use HasValues;

    case Connected = 'connected';
    case Silent = 'silent';
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Silent => 'Silent',
            self::Never => 'Never connected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Connected => 'healthy',
            self::Silent => 'urgent',
            self::Never => 'neutral',
        };
    }
}
