<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How urgent a ticket is. Drives the service level target.
 *
 * @see docs/04-open-questions.md Q3 — the SLA hours are provisional
 */
enum TicketPriority: string
{
    use HasValues;

    case Low = 'low';
    case Medium = 'medium';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::Critical => 'Critical',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Low => 'inactive',
            self::Medium => 'attention',
            self::Critical => 'urgent',
        };
    }

    /**
     * Hours from opening until the ticket is due, from config/atlas.php.
     *
     * PROVISIONAL: reading the value is fine; acting on a breach is Q3 work
     * and must not be built yet.
     */
    public function slaHours(): int
    {
        return (int) config('atlas.sla_hours.'.$this->value);
    }

    /** Highest priority first, for sorting the engineer's queue. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
