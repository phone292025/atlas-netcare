<?php

namespace Tests\Unit\Enums;

use App\Enums\RiskBand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RiskBand::fromScore() decides what a maintenance priority screen shows and
 * therefore which machines get serviced first. Boundary errors here are silent
 * — a mis-banded asset still looks plausible — so the boundaries are pinned.
 *
 * @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
 */
class RiskBandTest extends TestCase
{
    /** @return array<string, array{float, RiskBand}> */
    public static function scores(): array
    {
        return [
            'zero is low' => [0.0, RiskBand::Low],
            'just below medium' => [39.99, RiskBand::Low],
            'exactly medium boundary' => [40.0, RiskBand::Medium],
            'mid medium' => [52.0, RiskBand::Medium],
            'just below high' => [64.99, RiskBand::Medium],
            'exactly high boundary' => [65.0, RiskBand::High],
            'just below critical' => [84.99, RiskBand::High],
            'exactly critical boundary' => [85.0, RiskBand::Critical],
            'full score' => [100.0, RiskBand::Critical],
        ];
    }

    #[DataProvider('scores')]
    public function test_scores_band_at_the_configured_boundaries(float $score, RiskBand $expected): void
    {
        $this->assertSame($expected, RiskBand::fromScore($score));
    }

    public function test_scores_outside_the_range_fall_into_the_nearest_band(): void
    {
        $this->assertSame(RiskBand::Low, RiskBand::fromScore(-10.0));
        $this->assertSame(RiskBand::Critical, RiskBand::fromScore(250.0));
    }

    public function test_banding_follows_configuration_rather_than_hardcoded_numbers(): void
    {
        // Hard rule 3: no magic numbers. Retuning the bands in config must
        // change the outcome without touching any code.
        config(['atlas.risk_bands' => [
            'low' => 0,
            'medium' => 10,
            'high' => 20,
            'critical' => 30,
        ]]);

        $this->assertSame(RiskBand::Medium, RiskBand::fromScore(15.0));
        $this->assertSame(RiskBand::Critical, RiskBand::fromScore(35.0));
    }

    public function test_weight_orders_bands_most_urgent_first(): void
    {
        $bands = RiskBand::cases();

        usort($bands, static fn (RiskBand $a, RiskBand $b): int => $b->weight() <=> $a->weight());

        $this->assertSame(
            [RiskBand::Critical, RiskBand::High, RiskBand::Medium, RiskBand::Low],
            $bands
        );
    }
}
