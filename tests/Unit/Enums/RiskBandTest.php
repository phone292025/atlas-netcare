<?php

namespace Tests\Unit\Enums;

use App\Enums\RiskBand;
use App\Support\Tone;
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

    public function test_every_band_is_a_different_colour(): void
    {
        /*
         * Until 2026-09-04, Medium and High both returned the ATTENTION tone,
         * so M7's headline chart — the figure that makes this project answer
         * Case Study Category 4 — drew two of its four bars in the same amber
         * and the ranking screen gave them the same badge. A reader could not
         * tell a High-risk machine from a Medium one by looking.
         *
         * Every surface reads its colour from the tone, so asserting the tones
         * are distinct covers the badge, the gauge and the chart at once.
         */
        $tones = array_map(fn (RiskBand $band) => $band->tone(), RiskBand::cases());

        $this->assertSame($tones, array_unique($tones), 'Two risk bands share a tone.');

        $hexes = array_map(fn (RiskBand $band) => Tone::chart($band->tone()), RiskBand::cases());

        $this->assertSame($hexes, array_unique($hexes), 'Two risk bands chart in the same colour.');
    }

    public function test_the_band_colours_run_from_calm_to_alarming(): void
    {
        // The ramp is the information: a reader should be able to order the
        // bands by colour without reading the labels.
        $this->assertSame(
            [Tone::HEALTHY, Tone::ATTENTION, Tone::ELEVATED, Tone::URGENT],
            [
                RiskBand::Low->tone(),
                RiskBand::Medium->tone(),
                RiskBand::High->tone(),
                RiskBand::Critical->tone(),
            ],
        );
    }
}
