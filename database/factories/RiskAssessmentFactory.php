<?php

namespace Database\Factories;

use App\Enums\RiskBand;
use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\RiskAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A stored risk score.
 *
 * Real assessments come from RiskScoringService, which derives the band and
 * the factors from the score. This factory keeps those three consistent too,
 * because a fixture where the band disagrees with the score would let a test
 * pass against a bug that reversed them.
 *
 * @extends Factory<RiskAssessment>
 */
class RiskAssessmentFactory extends Factory
{
    protected $model = RiskAssessment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $score = $this->faker->randomFloat(2, 5, 95);

        return [
            'assessable_type' => LabPc::class,
            'assessable_id' => LabPc::factory(),
            'score' => $score,
            'band' => RiskBand::fromScore($score),
            'factors' => $this->breakdown($score),
            'calculated_at' => now(),
        ];
    }

    /** A specific score, with a band and breakdown that agree with it. */
    public function score(float $score): static
    {
        return $this->state([
            'score' => $score,
            'band' => RiskBand::fromScore($score),
            'factors' => $this->breakdown($score),
        ]);
    }

    /** Attach to a specific asset. Named forAsset because Factory::for() already exists and means something else. */
    public function forAsset(LabPc|AccessPoint $asset): static
    {
        return $this->state([
            'assessable_type' => $asset::class,
            'assessable_id' => $asset->id,
        ]);
    }

    /** Backdated, for building a trend to chart. */
    public function daysAgo(int $days): static
    {
        return $this->state(['calculated_at' => now()->subDays($days)]);
    }

    /**
     * Nothing could be measured. Not the same as a score of zero, and the
     * empty factors array is how the UI tells them apart.
     */
    public function unscored(): static
    {
        return $this->state([
            'score' => 0,
            'band' => RiskBand::Low,
            'factors' => [],
        ]);
    }

    /**
     * A breakdown that sums to the score, as a real one always does.
     *
     * @return array<string, float>
     */
    private function breakdown(float $score): array
    {
        return [
            'age' => round($score * 0.4, 2),
            'fault_frequency' => round($score * 0.3, 2),
            'critical_fail_rate' => round($score * 0.2, 2),
            'days_since_inspect' => round($score * 0.1, 2),
        ];
    }
}
