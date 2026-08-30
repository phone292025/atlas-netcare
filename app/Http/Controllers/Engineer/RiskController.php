<?php

namespace App\Http\Controllers\Engineer;

use App\Enums\RiskBand;
use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\RiskAssessment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Maintenance priority (FR-309, FR-310).
 *
 * The module's headline screen, and the thing that makes this project a
 * predictive maintenance system rather than a monitoring one. Everything else
 * reports what has already happened; this ranks what is about to.
 *
 * RANKED FROM THE CACHED COLUMNS, not from `risk_assessments`. Ordering a few
 * hundred assets by a correlated subquery over their assessment history would
 * be slow enough to notice, which is exactly why `lab_pcs.risk_score` exists.
 * The detail screen reads the assessment, because that is where the reasons
 * are. RiskScoringService writes both in one transaction so the two screens
 * cannot disagree.
 *
 * BOTH ASSET TYPES IN ONE RANKING. A maintenance queue that made you look at
 * two lists and compare them by eye would not be a priority order at all.
 * They are scored onto the same 0-100 scale by design so they can be merged.
 *
 * @see App\Services\Risk\RiskScoringService
 * @see docs/modules/M7-predictive-and-reports.md
 */
class RiskController extends Controller
{
    /** Assets that have never been scored sort last, not first. */
    private const UNSCORED = -1;

    public function index(Request $request): View
    {
        $band = $this->band($request);
        $location = $request->integer('location') ?: null;

        $pcs = LabPc::inService()
            ->with('location')
            ->when($band, fn (Builder $q) => $q->where('risk_band', $band->value))
            ->when($location, fn (Builder $q) => $q->where('location_id', $location))
            ->get();

        $aps = AccessPoint::query()
            ->with('location')
            ->when($band, fn (Builder $q) => $q->where('risk_band', $band->value))
            ->when($location, fn (Builder $q) => $q->where('location_id', $location))
            ->get();

        return view('engineer.risk.index', [
            'ranked' => $this->rank($pcs, $aps),
            'spread' => $this->spread(),
            'lastRun' => RiskAssessment::max('calculated_at'),
            'band' => $band,
            'location' => $location,
            'locations' => Location::orderBy('code')->get()
                ->mapWithKeys(fn (Location $l) => [$l->id => $l->label()])->all(),
        ]);
    }

    /**
     * One asset's score, its reasons, and how it got there.
     *
     * The trend is the figure worth putting in the report: a machine climbing
     * from 40 to 78 over a month is prediction. A single number is a snapshot
     * and could have come from a threshold rule.
     */
    public function show(Request $request, string $type, int $id): View
    {
        $asset = $this->resolve($type, $id);

        $history = $asset->riskAssessments()
            ->trend(90)
            ->get();

        return view('engineer.risk.show', [
            'asset' => $asset,
            'type' => $type,
            'latest' => $history->last(),
            'history' => $history,
            'chart' => [
                'labels' => $history->map(fn (RiskAssessment $a) => $a->calculated_at->format('j M'))->all(),
                'scores' => $history->map(fn (RiskAssessment $a) => round($a->score, 1))->all(),
            ],
        ]);
    }

    /**
     * Merge both asset types into one priority order.
     *
     * Sorted in PHP rather than by a UNION in SQL. The two tables have
     * genuinely different columns and a union would need every one of them
     * aliased into a common shape — more code, and it would break quietly
     * whenever either table gained a column. The set being ordered is the
     * campus asset inventory, which is hundreds of rows, not millions.
     *
     * @param  Collection<int, LabPc>  $pcs
     * @param  Collection<int, AccessPoint>  $aps
     * @return Collection<int, array<string, mixed>>
     */
    private function rank(Collection $pcs, Collection $aps): Collection
    {
        return $pcs->map(fn (LabPc $pc) => [
            'type' => 'lab-pc',
            'id' => $pc->id,
            'name' => $pc->asset_tag,
            'detail' => $pc->hostname,
            'location' => $pc->location?->label(),
            'score' => $pc->risk_score,
            'band' => $pc->risk_band,
        ])->concat($aps->map(fn (AccessPoint $ap) => [
            'type' => 'access-point',
            'id' => $ap->id,
            'name' => $ap->ap_code,
            'detail' => $ap->model,
            'location' => $ap->location?->label(),
            'score' => $ap->risk_score,
            'band' => $ap->risk_band,
        ]))
            // Unscored assets sort last. They are not low risk — nothing is
            // known about them — and putting an unknown at the top of a
            // maintenance queue would bury the assets that are genuinely
            // about to fail.
            ->sortByDesc(fn (array $row) => $row['score'] ?? self::UNSCORED)
            ->values();
    }

    /**
     * How many assets sit in each band, for the summary strip.
     *
     * Reads the cached columns so it always agrees with the ranking below it.
     *
     * @return array<string, int>
     */
    private function spread(): array
    {
        $counts = [];

        foreach (RiskBand::cases() as $band) {
            $counts[$band->value] = LabPc::inService()->where('risk_band', $band->value)->count()
                + AccessPoint::query()->where('risk_band', $band->value)->count();
        }

        return $counts;
    }

    private function band(Request $request): ?RiskBand
    {
        return $request->filled('band')
            ? RiskBand::tryFrom((string) $request->string('band'))
            : null;
    }

    /**
     * Turn a URL segment into the asset it names.
     *
     * A whitelist rather than a class name from the URL. `assessable_type`
     * holds a fully qualified class name, and letting a request supply one
     * would be a route to instantiating whatever a visitor typed.
     */
    private function resolve(string $type, int $id): LabPc|AccessPoint
    {
        return match ($type) {
            'lab-pc' => LabPc::with('location')->findOrFail($id),
            'access-point' => AccessPoint::with('location')->findOrFail($id),
            default => abort(404),
        };
    }
}
