<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The band a predictive risk score falls into.
 *
 * Bands come from config/atlas.php so they can be tuned without a code change
 * — including live during the demo.
 *
 * @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
 */
enum RiskBand: string
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
            self::Medium, self::High => 'attention',
            self::Critical => 'urgent',
        };
    }

    /**
     * Band a 0-100 score into a risk level.
     *
     * Bounds are inclusive lower bounds: with the default configuration a
     * score of 40 is Medium, 64 is still Medium, and 65 is High. Scores are
     * clamped, so anything outside 0-100 lands in the nearest band.
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
