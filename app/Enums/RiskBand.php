<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * The band a predictive risk score falls into.
 *
 * Bands come from config/atlas.php so they can be tuned without a code change
 * — including live during the demo.
 *
 * @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
 */
enum RiskBand: string implements HasLabel, HasTone
{
    use HasValues;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low Risk',
            self::Medium => 'Medium Risk',
            self::High => 'High Risk',
            self::Critical => 'Critical Risk',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Low => 'healthy',
            self::Medium => 'attention',

            // Its own tone since 2026-09-04. Medium and High shared
            // 'attention', so the risk chart drew two of its four bars in the
            // same amber and the ranking screen gave them the same badge.
            self::High => 'elevated',

            self::Critical => 'urgent',
        };
    }

    /**
     * Band a 0-100 score into a risk level.
     *
     * Bounds are inclusive lower bounds: with the default configuration a
     * score of 40 is Medium, 64 is still Medium, and 65 is High.
     *
     * The input is not clamped. It does not need to be — the comparison chain
     * puts anything above the top bound in Critical and anything below zero in
     * Low, so out-of-range scores land in the nearest band on their own.
     */
    public static function fromScore(float $score): self
    {
        $bounds = config('atlas.risk_bands');

        return match (true) {
            $score >= $bounds['critical'] => self::Critical,
            $score >= $bounds['high'] => self::High,
            $score >= $bounds['medium'] => self::Medium,
            default => self::Low,
        };
    }

    /** Highest risk first, for ranking the maintenance priority screen. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
