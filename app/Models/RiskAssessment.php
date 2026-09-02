<?php

namespace App\Models;

use App\Enums\RiskBand;
use Database\Factories\RiskAssessmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One predictive risk score for one asset, at one moment.
 *
 * Written only by RiskScoringService, which writes it in the same transaction
 * as the cached `risk_score` / `risk_band` columns on the asset. Nothing else
 * should create one: a row here that disagrees with the asset's cache makes
 * the ranking screen and the detail screen tell different stories about the
 * same machine.
 *
 * @see App\Services\Risk\RiskScoringService
 * @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
 */
class RiskAssessment extends Model
{
    /** @use HasFactory<RiskAssessmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'assessable_type', 'assessable_id', 'score', 'band', 'factors', 'calculated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            /*
             * decimal:2, matching `lab_pcs.risk_score` and
             * `access_points.risk_score`.
             *
             * It was `float` while the cached columns were `decimal:2`, so the
             * same number came back as 46.75 from the assessment and '46.79'
             * from the asset — different types for one quantity, which makes
             * any strict comparison between them false and serialises the two
             * differently in JSON. The project convention is decimal for
             * numbers a person reads.
             */
            'score' => 'decimal:2',
            'band' => RiskBand::class,
            'factors' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function assessable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Newest first.
     *
     * `id` breaks the tie because a whole run shares one `calculated_at` —
     * that is deliberate, so a run can be selected as a unit — and ordering on
     * the date alone would leave rows from the same run in arbitrary order.
     *
     * @param  Builder<RiskAssessment>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('calculated_at')->orderByDesc('id');
    }

    /**
     * The chart's data, oldest first, as a trend reads.
     *
     * @param  Builder<RiskAssessment>  $query
     */
    public function scopeTrend(Builder $query, int $days = 90): void
    {
        $query->where('calculated_at', '>=', now()->subDays($days))
            ->orderBy('calculated_at');
    }

    /**
     * The heaviest contributing factors, largest first.
     *
     * This is the sentence the module exists to be able to say. A score of 78
     * is a number; "78, mostly age and six weeks without an inspection" is the
     * analytics the case study category asks for.
     *
     * @return array<string, float>
     */
    public function topFactors(int $limit = 3): array
    {
        return collect($this->factors ?? [])
            ->map(fn ($v) => (float) $v)
            ->sortDesc()
            ->take($limit)
            ->all();
    }
}
