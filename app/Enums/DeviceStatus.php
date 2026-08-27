<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * Operational state of a lab PC.
 *
 * Degraded means still working but below standard. Faulty means not working.
 * They are different states with different urgency — see docs/glossary.md.
 */
enum DeviceStatus: string implements HasLabel, HasTone
{
    use HasValues;

    case Operational = 'operational';
    case Degraded = 'degraded';
    case Faulty = 'faulty';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Operational',
            self::Degraded => 'Degraded',
            self::Faulty => 'Faulty',
            self::Retired => 'Retired',
        };
    }

    /**
     * Semantic tone, mapped to Tailwind classes in one place by
     * <x-status-badge>. See docs/08-ui-conventions.md.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Operational => 'healthy',
            self::Degraded => 'attention',
            self::Faulty => 'urgent',
            self::Retired => 'inactive',
        };
    }

    /** Retired machines are excluded from inspection rounds and risk scoring. */
    public function isActive(): bool
    {
        return $this !== self::Retired;
    }
}
